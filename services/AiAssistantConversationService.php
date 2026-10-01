<?php

final class AiAssistantConversationService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function create(int $userId, ?int $churchId, string $title = 'New conversation'): int
    {
        $title = trim($title) ?: 'New conversation';
        $stmt = $this->conn->prepare(
            'INSERT INTO ai_assistant_conversations (user_id, church_id, title) VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $userId, $churchId, $title);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function assertOwned(int $conversationId, int $userId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, title, status FROM ai_assistant_conversations WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $conversationId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['status'] !== 'active') {
            throw new DomainException('Conversation not found.');
        }
        return $row;
    }

    public function list(int $userId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, title, last_message_at, created_at FROM ai_assistant_conversations"
            . " WHERE user_id = ? AND status = 'active'"
            . ' ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT 30'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function history(int $conversationId, int $userId, int $limit = 50): array
    {
        $this->assertOwned($conversationId, $userId);
        $limit = max(1, min(100, $limit));
        $stmt = $this->conn->prepare(
            'SELECT id, sender, message_text, data_domain, processing_mode, created_at'
            . ' FROM ai_assistant_messages WHERE conversation_id = ?'
            . ' ORDER BY id DESC LIMIT ?'
        );
        $stmt->bind_param('ii', $conversationId, $limit);
        $stmt->execute();
        $rows = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
        return $rows;
    }

    public function addMessage(
        int $conversationId,
        string $sender,
        string $text,
        ?string $domain,
        ?string $mode,
        ?string $requestId = null
    ): int {
        $stmt = $this->conn->prepare(
            'INSERT INTO ai_assistant_messages'
            . ' (conversation_id, sender, message_text, data_domain, processing_mode, provider_request_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssss', $conversationId, $sender, $text, $domain, $mode, $requestId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        $this->conn->query(
            'UPDATE ai_assistant_conversations SET last_message_at = NOW() WHERE id = ' . $conversationId
        );
        return $id;
    }

    public function setTitleFromQuestion(int $conversationId, string $currentTitle, string $question): void
    {
        if ($currentTitle !== 'New conversation') {
            return;
        }
        $title = trim(preg_replace('/\s+/', ' ', $question) ?: $question);
        $title = mb_substr($title, 0, 80);
        $stmt = $this->conn->prepare('UPDATE ai_assistant_conversations SET title = ? WHERE id = ?');
        $stmt->bind_param('si', $title, $conversationId);
        $stmt->execute();
        $stmt->close();
    }

    public function archive(int $conversationId, int $userId): void
    {
        $this->assertOwned($conversationId, $userId);
        $stmt = $this->conn->prepare(
            "UPDATE ai_assistant_conversations SET status = 'archived' WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $conversationId, $userId);
        $stmt->execute();
        $stmt->close();
    }
}
