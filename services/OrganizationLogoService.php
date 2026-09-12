<?php

class OrganizationLogoService {
    private const MAX_FILE_SIZE = 2097152;
    private const MAX_DIMENSION = 4096;
    private const MAX_PIXELS = 16000000;

    private mysqli $conn;
    private string $uploadRoot;
    private string $organizationDirectory;

    public function __construct(mysqli $conn, ?string $uploadRoot = null) {
        $this->conn = $conn;
        $this->uploadRoot = rtrim($uploadRoot ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads', DIRECTORY_SEPARATOR);
        $this->organizationDirectory = $this->uploadRoot . DIRECTORY_SEPARATOR . 'organizations';
    }

    public function uploadOrganizationLogo(
        int $organizationId,
        array $file,
        string $altText,
        ?int $actorUserId
    ): array {
        return $this->replaceLogo('organization', $organizationId, $organizationId, $file, $altText, $actorUserId);
    }

    public function uploadUnitLogo(
        int $organizationId,
        int $unitId,
        array $file,
        string $altText,
        ?int $actorUserId
    ): array {
        return $this->replaceLogo('organization_unit', $organizationId, $unitId, $file, $altText, $actorUserId);
    }

    public function removeOrganizationLogo(int $organizationId, ?int $actorUserId): bool {
        return $this->removeLogo('organization', $organizationId, $organizationId, $actorUserId);
    }

    public function removeUnitLogo(int $organizationId, int $unitId, ?int $actorUserId): bool {
        return $this->removeLogo('organization_unit', $organizationId, $unitId, $actorUserId);
    }

    /**
     * Inspect image contents independently of the browser-supplied extension/MIME.
     * This public method also supports deterministic validation tests.
     */
    public static function inspectImage(string $temporaryPath): array {
        if ($temporaryPath === '' || !is_file($temporaryPath) || !is_readable($temporaryPath)) {
            throw new RuntimeException('The uploaded logo could not be read.');
        }

        $size = filesize($temporaryPath);
        if ($size === false || $size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Logo size must be between 1 byte and 2 MB.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? (string) finfo_file($finfo, $temporaryPath) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mimeType])) {
            throw new RuntimeException('Logo must be a JPG, PNG, or WebP image.');
        }

        $imageInfo = @getimagesize($temporaryPath);
        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        $detectedMime = (string) ($imageInfo['mime'] ?? '');
        if ($width < 1 || $height < 1 || $detectedMime !== $mimeType) {
            throw new RuntimeException('The logo file is not a valid image.');
        }
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ($width * $height) > self::MAX_PIXELS) {
            throw new RuntimeException('Logo dimensions must not exceed 4096 x 4096 pixels or 16 megapixels.');
        }

        return [
            'mime_type' => $mimeType,
            'extension' => $extensions[$mimeType],
            'file_size' => (int) $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function replaceLogo(
        string $entityType,
        int $organizationId,
        int $entityId,
        array $file,
        string $altText,
        ?int $actorUserId
    ): array {
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($uploadError));
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($temporaryPath)) {
            throw new RuntimeException('The logo upload was not received through a valid HTTP upload.');
        }
        $metadata = self::inspectImage($temporaryPath);
        $altText = $this->normalizeAltText($altText);
        $storedAbsolutePath = null;

        $this->conn->begin_transaction();
        try {
            $entity = $this->lockEntity($entityType, $organizationId, $entityId);
            if ($altText === '') {
                $altText = $entity['name'] . ' logo';
            }

            $this->ensureUploadDirectory();
            $prefix = $entityType === 'organization' ? 'org' : 'unit';
            $storedName = $prefix . '_' . $entityId . '_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $metadata['extension'];
            $storedRelativePath = 'organizations/' . $storedName;
            $storedAbsolutePath = $this->organizationDirectory . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($temporaryPath, $storedAbsolutePath)) {
                throw new RuntimeException('The server could not store the uploaded logo.');
            }
            @chmod($storedAbsolutePath, 0644);

            $previousPath = $entity['logo_path'] ?: null;
            $this->updateEntityLogo($entityType, $entityId, $storedRelativePath, $altText);
            $this->writeAudit(
                $organizationId,
                $entityType,
                $entityId,
                $entity['name'],
                $previousPath ? 'replaced' : 'uploaded',
                $previousPath,
                $storedRelativePath,
                $altText,
                $metadata,
                $actorUserId
            );
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            if ($storedAbsolutePath && is_file($storedAbsolutePath)) {
                @unlink($storedAbsolutePath);
            }
            throw $e;
        }

        if (!empty($entity['logo_path']) && $entity['logo_path'] !== $storedRelativePath) {
            $this->deleteManagedFile($entity['logo_path']);
        }

        return [
            'path' => $storedRelativePath,
            'alt_text' => $altText,
            'metadata' => $metadata,
        ];
    }

    private function removeLogo(
        string $entityType,
        int $organizationId,
        int $entityId,
        ?int $actorUserId
    ): bool {
        $this->conn->begin_transaction();
        try {
            $entity = $this->lockEntity($entityType, $organizationId, $entityId);
            $previousPath = $entity['logo_path'] ?: null;
            if ($previousPath === null) {
                $this->conn->commit();
                return false;
            }

            $this->updateEntityLogo($entityType, $entityId, null, null);
            $this->writeAudit(
                $organizationId,
                $entityType,
                $entityId,
                $entity['name'],
                'removed',
                $previousPath,
                null,
                null,
                [],
                $actorUserId
            );
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        $this->deleteManagedFile($previousPath);
        return true;
    }

    private function lockEntity(string $entityType, int $organizationId, int $entityId): array {
        if ($entityType === 'organization') {
            $stmt = $this->conn->prepare(
                'SELECT id, name, logo_path, logo_alt_text
                   FROM organizations
                  WHERE id = ? AND id = ?
                  FOR UPDATE'
            );
            $stmt->bind_param('ii', $entityId, $organizationId);
        } else {
            $stmt = $this->conn->prepare(
                'SELECT id, name, logo_path, logo_alt_text
                   FROM organization_units
                  WHERE id = ? AND organization_id = ?
                  FOR UPDATE'
            );
            $stmt->bind_param('ii', $entityId, $organizationId);
        }
        $stmt->execute();
        $entity = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$entity) {
            throw new RuntimeException('The selected organization or unit was not found in your authorized scope.');
        }
        return $entity;
    }

    private function updateEntityLogo(string $entityType, int $entityId, ?string $path, ?string $altText): void {
        $table = $entityType === 'organization' ? 'organizations' : 'organization_units';
        $stmt = $this->conn->prepare("UPDATE {$table} SET logo_path = ?, logo_alt_text = ? WHERE id = ?");
        $stmt->bind_param('ssi', $path, $altText, $entityId);
        $stmt->execute();
        $stmt->close();
    }

    private function writeAudit(
        int $organizationId,
        string $entityType,
        int $entityId,
        string $entityName,
        string $action,
        ?string $previousPath,
        ?string $newPath,
        ?string $altText,
        array $metadata,
        ?int $actorUserId
    ): void {
        $mimeType = $metadata['mime_type'] ?? null;
        $fileSize = $metadata['file_size'] ?? null;
        $width = $metadata['width'] ?? null;
        $height = $metadata['height'] ?? null;
        $stmt = $this->conn->prepare(
            'INSERT INTO organization_media_history
                (organization_id, entity_type, entity_id, entity_name, action,
                 previous_path, new_path, alt_text, mime_type, file_size,
                 image_width, image_height, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'isissssssiiii',
            $organizationId,
            $entityType,
            $entityId,
            $entityName,
            $action,
            $previousPath,
            $newPath,
            $altText,
            $mimeType,
            $fileSize,
            $width,
            $height,
            $actorUserId
        );
        $stmt->execute();
        $stmt->close();
    }

    private function normalizeAltText(string $altText): string {
        $altText = trim(preg_replace('/\s+/', ' ', $altText) ?? '');
        if (mb_strlen($altText) > 160) {
            throw new RuntimeException('Logo description must be 160 characters or fewer.');
        }
        return $altText;
    }

    private function ensureUploadDirectory(): void {
        if (!is_dir($this->organizationDirectory)
            && !mkdir($this->organizationDirectory, 0755, true)
            && !is_dir($this->organizationDirectory)) {
            throw new RuntimeException('The organization logo storage directory could not be created.');
        }
        if (!is_writable($this->organizationDirectory)) {
            throw new RuntimeException('The organization logo storage directory is not writable.');
        }
    }

    private function deleteManagedFile(string $relativePath): void {
        if (!preg_match('#^organizations/(?:org|unit)_[A-Za-z0-9_]+\.(?:jpg|png|webp)$#', $relativePath)) {
            return;
        }
        $absolutePath = $this->uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function uploadErrorMessage(int $error): string {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The logo exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The logo exceeds the 2 MB form limit.',
            UPLOAD_ERR_PARTIAL => 'The logo upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a logo image to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded logo.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the logo upload.',
        ];
        return $messages[$error] ?? 'The logo upload failed.';
    }
}
