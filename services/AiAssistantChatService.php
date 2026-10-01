<?php

require_once __DIR__ . '/AiAssistantSettingsService.php';
require_once __DIR__ . '/AiAssistantConversationService.php';
require_once __DIR__ . '/OpenAiResponsesService.php';
require_once __DIR__ . '/DashboardInsightsService.php';

final class AiAssistantChatService
{
    private mysqli $conn;
    private int $userId;
    private ?int $churchId;
    private DashboardInsightsService $local;
    private AiAssistantSettingsService $settings;
    private AiAssistantConversationService $conversations;

    public function __construct(
        mysqli $conn,
        int $userId,
        ?int $churchId,
        DashboardInsightsService $local
    ) {
        $this->conn = $conn;
        $this->userId = $userId;
        $this->churchId = $churchId;
        $this->local = $local;
        $this->settings = new AiAssistantSettingsService($conn);
        $this->conversations = new AiAssistantConversationService($conn);
    }

    public function ask(?int $conversationId, string $question): array
    {
        $config = $this->settings->get();
        if (empty($config['is_enabled'])) {
            throw new DomainException('The assistant is currently disabled.');
        }
        $question = trim($question);
        if ($question === '' || mb_strlen($question) < 3 || mb_strlen($question) > 500) {
            throw new InvalidArgumentException('Enter a question between 3 and 500 characters.');
        }

        if ($conversationId) {
            $conversation = $this->conversations->assertOwned($conversationId, $this->userId);
        } else {
            $conversationId = $this->conversations->create($this->userId, $this->churchId);
            $conversation = ['title' => 'New conversation'];
        }
        $history = $this->conversations->history($conversationId, $this->userId, 12);
        $this->conversations->addMessage($conversationId, 'user', $question, null, null);
        $this->conversations->setTitleFromQuestion($conversationId, (string) $conversation['title'], $question);

        $localResult = $this->local->ask($question);
        $localAnswer = (string) $localResult['answer'];
        $mode = (string) $config['assistant_mode'];
        $answer = $localAnswer;
        $sender = 'local';
        $requestId = null;
        $agentAvailable = false;
        $notice = null;

        if ($mode === 'agent' || $mode === 'both') {
            try {
                $agentResult = (new OpenAiResponsesService())->respond(
                    (string) $config['agent_model'],
                    (int) $config['max_output_tokens'],
                    $history,
                    $question,
                    $localAnswer
                );
                $answer = $agentResult['text'];
                $requestId = $agentResult['request_id'] ?: null;
                $sender = 'agent';
                $agentAvailable = true;
            } catch (Throwable $exception) {
                error_log('AI assistant agent fallback: ' . $exception->getMessage());
                $notice = $mode === 'agent'
                    ? 'AI Agent unavailable; the verified local answer is shown instead.'
                    : 'AI Agent unavailable; Local mode answered this question.';
            }
        }

        $messageId = $this->conversations->addMessage(
            $conversationId,
            $sender,
            $answer,
            (string) ($localResult['domain'] ?? 'unknown'),
            $mode,
            $requestId
        );

        return [
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'answer' => $answer,
            'local_answer' => $mode === 'both' && $agentAvailable ? $localAnswer : null,
            'mode' => $mode,
            'source' => $sender,
            'notice' => $notice,
            'suggestions' => $localResult['suggestions'] ?? [],
        ];
    }

    public function conversations(): array
    {
        return $this->conversations->list($this->userId);
    }

    public function history(int $conversationId): array
    {
        return $this->conversations->history($conversationId, $this->userId);
    }

    public function archive(int $conversationId): void
    {
        $this->conversations->archive($conversationId, $this->userId);
    }
}
