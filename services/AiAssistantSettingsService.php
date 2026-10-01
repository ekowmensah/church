<?php

final class AiAssistantSettingsService
{
    public const DEFAULT_MODEL = 'gpt-6-luna';

    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function get(): array
    {
        $result = $this->conn->query(
            'SELECT assistant_mode, agent_model, max_output_tokens,'
            . ' requests_per_user_per_minute, is_enabled'
            . ' FROM ai_assistant_settings WHERE id = 1 LIMIT 1'
        );
        $row = $result ? $result->fetch_assoc() : null;
        if ($row) return $row;
        return [
            'assistant_mode' => 'local',
            'agent_model' => self::DEFAULT_MODEL,
            'max_output_tokens' => 700,
            'requests_per_user_per_minute' => 10,
            'is_enabled' => 1,
        ];
    }

    public function update(array $input, int $actorUserId): void
    {
        $mode = strtolower(trim((string) ($input['assistant_mode'] ?? 'local')));
        if (!in_array($mode, ['local', 'agent', 'both'], true)) {
            throw new InvalidArgumentException('Select Local, AI Agent, or Both.');
        }
        $model = $this->validateModel((string) ($input['agent_model'] ?? self::DEFAULT_MODEL));
        $maxTokens = max(100, min(4000, (int) ($input['max_output_tokens'] ?? 700)));
        $rateLimit = max(1, min(60, (int) ($input['requests_per_user_per_minute'] ?? 10)));
        $enabled = !empty($input['is_enabled']) ? 1 : 0;

        $stmt = $this->conn->prepare(
            'INSERT INTO ai_assistant_settings'
            . ' (id, assistant_mode, agent_model, max_output_tokens, requests_per_user_per_minute, is_enabled, updated_by_user_id)'
            . ' VALUES (1, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE assistant_mode = VALUES(assistant_mode),'
            . ' agent_model = VALUES(agent_model), max_output_tokens = VALUES(max_output_tokens),'
            . ' requests_per_user_per_minute = VALUES(requests_per_user_per_minute),'
            . ' is_enabled = VALUES(is_enabled), updated_by_user_id = VALUES(updated_by_user_id)'
        );
        $stmt->bind_param('ssiiii', $mode, $model, $maxTokens, $rateLimit, $enabled, $actorUserId);
        $stmt->execute();
        $stmt->close();
    }

    public function hasApiKey(): bool
    {
        return trim((string) getenv('OPENAI_API_KEY')) !== '';
    }

    public function supportedModels(): array
    {
        return [
            'gpt-6-luna' => 'GPT-6 Luna — lowest-cost focused model (recommended)',
            'gpt-4o-mini' => 'GPT-4o mini — economical legacy option',
            'gpt-4.1-mini' => 'GPT-4.1 mini — stronger instruction following',
        ];
    }

    public function validateModel(string $model): string
    {
        $model = trim($model);
        if (!array_key_exists($model, $this->supportedModels())) {
            throw new InvalidArgumentException('Select one of the supported OpenAI models.');
        }
        return $model;
    }
}
