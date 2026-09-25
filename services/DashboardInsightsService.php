<?php

require_once __DIR__ . '/DashboardPeriodSummaryService.php';

final class DashboardInsightsService
{
    private mysqli $conn;
    private int $userId;
    private bool $superAdmin;
    private bool $cashier;
    private ?int $churchId;
    private ?array $classIds;
    private ?array $organizationIds;
    private array $permissions;

    public function __construct(
        mysqli $conn,
        int $userId,
        bool $superAdmin,
        bool $cashier,
        ?int $churchId,
        ?array $classIds,
        ?array $organizationIds,
        array $permissions
    ) {
        $this->conn = $conn;
        $this->userId = $userId;
        $this->superAdmin = $superAdmin;
        $this->cashier = $cashier;
        $this->churchId = $churchId && $churchId > 0 ? $churchId : null;
        $this->classIds = $this->normalizeIds($classIds);
        $this->organizationIds = $this->normalizeIds($organizationIds);
        $this->permissions = $permissions;
    }

    public function ask(string $question): array
    {
        $question = trim($question);
        if ($question === '' || mb_strlen($question) < 3) {
            throw new InvalidArgumentException('Enter a question with at least three characters.');
        }
        if (mb_strlen($question) > 250) {
            throw new InvalidArgumentException('Keep the question within 250 characters.');
        }

        $normalized = mb_strtolower($question);
        $normalized = preg_replace('/[^a-z0-9\s-]+/u', ' ', $normalized) ?: '';
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?: '';
        $domain = $this->detectDomain($normalized);
        $period = $this->detectPeriod($normalized, $domain);
        $intent = $domain . '_' . ($period ?: 'summary');
        $answered = false;

        try {
            if ($domain === 'help') {
                $answer = $this->helpAnswer();
                $intent = 'help_supported_questions';
                $answered = true;
            } elseif ($domain === 'payments' && preg_match('/\b(have i paid|i paid|my payment|my payments|my giving)\b/', $normalized)) {
                $answer = $this->personalPaymentAnswer($period ?: 'overall');
                $intent = 'payments_personal_' . ($period ?: 'overall');
                $answered = true;
            } elseif ($domain === 'payments' || $domain === 'attendance' || $domain === 'health') {
                $answer = $this->periodMetricAnswer($domain, $period ?: 'overall');
                $answered = true;
            } elseif ($domain === 'membership') {
                $membership = $this->membershipAnswer($normalized);
                $answer = $membership['answer'];
                $intent = $membership['intent'];
                $period = null;
                $answered = true;
            } elseif ($domain === 'events') {
                $event = $this->eventAnswer($period ?: 'upcoming');
                $answer = $event['answer'];
                $period = $event['period'];
                $answered = true;
            } elseif ($domain === 'birthdays') {
                $birthday = $this->birthdayAnswer($period ?: 'today');
                $answer = $birthday['answer'];
                $period = $birthday['period'];
                $answered = true;
            } else {
                $answer = 'I could not match that question to an available dashboard metric. ' . $this->helpAnswer();
                $intent = 'unknown_question';
            }
        } catch (DomainException $exception) {
            $answer = $exception->getMessage();
            $intent = $domain . '_not_authorized';
        }

        $this->recordAudit($intent, $domain, $period, $answered);

        return [
            'answered' => $answered,
            'answer' => $answer,
            'domain' => $domain,
            'period' => $period,
            'intent' => $intent,
            'processing' => 'local',
            'suggestions' => $this->suggestions(),
        ];
    }

    private function periodMetricAnswer(string $domain, string $period): string
    {
        $permissionMap = [
            'payments' => 'payments',
            'attendance' => 'attendance',
            'health' => 'health',
        ];
        if (empty($this->permissions[$permissionMap[$domain]])) {
            throw new DomainException('You do not have permission to view that dashboard information.');
        }

        $summary = (new DashboardPeriodSummaryService(
            $this->conn,
            $this->userId,
            $this->superAdmin,
            $this->cashier,
            $this->churchId,
            $this->classIds,
            $this->organizationIds
        ))->summarize(
            $domain === 'payments',
            $domain === 'attendance',
            $domain === 'health'
        );

        if (!isset($summary['periods'][$period])) {
            $period = 'overall';
        }
        $label = $summary['periods'][$period]['label'] ?? 'Overall';
        $value = $summary[$domain][$period] ?? 0;

        if ($domain === 'payments') {
            return sprintf(
                'Posted payments for %s total GHS %s within your authorized scope.',
                $label,
                number_format((float) $value, 2)
            );
        }
        if ($domain === 'attendance') {
            return sprintf(
                'Approved present attendance for %s is %s within your authorized scope.',
                $label,
                number_format((int) $value)
            );
        }
        return sprintf(
            'Health records for %s total %s within your authorized scope.',
            $label,
            number_format((int) $value)
        );
    }

    private function membershipAnswer(string $question): array
    {
        if (empty($this->permissions['membership'])) {
            throw new DomainException('You do not have permission to view membership dashboard information.');
        }

        $scope = $this->memberScope('member');
        $label = 'active members';
        $intent = 'membership_active';
        $condition = "member.status = 'active' AND COALESCE(member.is_archived, 0) = 0";

        if (strpos($question, 'full member') !== false) {
            $label = 'Full Members';
            $intent = 'membership_full';
            $condition .= " AND member.membership_status = 'Full Member'";
        } elseif (strpos($question, 'catechumen') !== false) {
            $label = 'Catechumens';
            $intent = 'membership_catechumen';
            $condition .= " AND member.membership_status = 'Catechumen'";
        } elseif (strpos($question, 'adherent') !== false) {
            $label = 'Adherents';
            $intent = 'membership_adherent';
            $condition .= " AND member.membership_status = 'Adherent'";
        } elseif (strpos($question, 'distant') !== false) {
            $label = 'Distant Members';
            $intent = 'membership_distant';
            $condition .= " AND member.membership_status = 'Distant Member'";
        } elseif (strpos($question, 'invalid') !== false) {
            $label = 'Invalid Members';
            $intent = 'membership_invalid';
            $condition .= " AND member.membership_status = 'Invalid'";
        } elseif (strpos($question, 'junior') !== false) {
            $label = 'Junior Members';
            $intent = 'membership_junior';
            $memberCondition = $condition . " AND member.membership_status = 'Junior Member'";
            $memberCount = $this->scalar("SELECT COUNT(*) AS total FROM members member WHERE {$memberCondition} AND {$scope}");
            $childCount = $this->sundaySchoolCount();
            $total = $memberCount + $childCount;
            return [
                'intent' => $intent,
                'answer' => sprintf('There are %s %s within your authorized scope.', number_format($total), $label),
            ];
        } elseif (strpos($question, 'christian community') !== false || strpos($question, 'community size') !== false) {
            $label = 'people in the Christian Community total';
            $intent = 'membership_christian_community';
            $condition .= " AND member.membership_status IN ('Full Member','Catechumen','Adherent','Junior Member')";
            $memberCount = $this->scalar("SELECT COUNT(*) AS total FROM members member WHERE {$condition} AND {$scope}");
            $total = $memberCount + $this->sundaySchoolCount();
            return [
                'intent' => $intent,
                'answer' => sprintf('There are %s %s within your authorized scope.', number_format($total), $label),
            ];
        }

        $total = $this->scalar("SELECT COUNT(*) AS total FROM members member WHERE {$condition} AND {$scope}");
        return [
            'intent' => $intent,
            'answer' => sprintf('There are %s %s within your authorized scope.', number_format($total), $label),
        ];
    }

    private function personalPaymentAnswer(string $period): string
    {
        if (empty($this->permissions['payments'])) {
            throw new DomainException('You do not have permission to view payment dashboard information.');
        }

        $stmt = $this->conn->prepare('SELECT member_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $memberId = (int) ($stmt->get_result()->fetch_assoc()['member_id'] ?? 0);
        $stmt->close();
        if ($memberId < 1) {
            return 'Your user account is not linked to a member record, so a personal payment total is unavailable.';
        }

        $periodConditions = [
            'today' => 'DATE(payment_date) = CURDATE()',
            'yesterday' => 'DATE(payment_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)',
            'this_week' => 'YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1)',
            'last_week' => 'YEARWEEK(payment_date, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)',
            'this_month' => 'YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())',
            'last_month' => "payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')"
                . " AND payment_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')",
            'this_year' => 'YEAR(payment_date) = YEAR(CURDATE())',
            'last_year' => 'YEAR(payment_date) = YEAR(CURDATE()) - 1',
            'overall' => '1=1',
        ];
        if (!isset($periodConditions[$period])) $period = 'overall';
        $labels = [
            'today' => 'today', 'yesterday' => 'yesterday',
            'this_week' => 'this week', 'last_week' => 'last week',
            'this_month' => 'this month', 'last_month' => 'last month',
            'this_year' => 'this year', 'last_year' => 'last year',
            'overall' => 'overall',
        ];

        $stmt = $this->conn->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM v_posted_payments'
            . ' WHERE member_id = ? AND ' . $periodConditions[$period]
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $total = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return sprintf('Your posted payments %s total GHS %s.', $labels[$period], number_format($total, 2));
    }

    private function eventAnswer(string $period): array
    {
        if (empty($this->permissions['events'])) {
            throw new DomainException('You do not have permission to view event dashboard information.');
        }
        $scope = $this->churchScope('event');
        $condition = "event.status = 'active'";
        $label = 'upcoming';
        if ($period === 'today') {
            $condition .= ' AND event.event_date = CURDATE()';
            $label = 'today';
        } elseif ($period === 'this_month') {
            $condition .= ' AND YEAR(event.event_date) = YEAR(CURDATE()) AND MONTH(event.event_date) = MONTH(CURDATE())';
            $label = 'this month';
        } else {
            $period = 'upcoming';
            $condition .= ' AND event.event_date >= CURDATE()';
        }
        $total = $this->scalar("SELECT COUNT(*) AS total FROM events event WHERE {$condition} AND {$scope}");
        return [
            'period' => $period,
            'answer' => sprintf('There are %s active events %s within your authorized scope.', number_format($total), $label),
        ];
    }

    private function birthdayAnswer(string $period): array
    {
        if (empty($this->permissions['birthdays'])) {
            throw new DomainException('You do not have permission to view birthday information.');
        }
        $scope = $this->memberScope('member');
        if ($period === 'tomorrow') {
            $dateExpression = "DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '%m-%d')";
            $label = 'tomorrow';
        } elseif ($period === 'yesterday') {
            $dateExpression = "DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '%m-%d')";
            $label = 'yesterday';
        } elseif ($period === 'this_month') {
            $total = $this->scalar(
                "SELECT COUNT(*) AS total FROM members member
                 WHERE member.status = 'active' AND COALESCE(member.is_archived, 0) = 0
                   AND member.dob IS NOT NULL AND MONTH(member.dob) = MONTH(CURDATE()) AND {$scope}"
            );
            return [
                'period' => 'this_month',
                'answer' => sprintf('%s members have birthdays this month within your authorized scope.', number_format($total)),
            ];
        } else {
            $period = 'today';
            $dateExpression = "DATE_FORMAT(CURDATE(), '%m-%d')";
            $label = 'today';
        }
        $total = $this->scalar(
            "SELECT COUNT(*) AS total FROM members member
             WHERE member.status = 'active' AND COALESCE(member.is_archived, 0) = 0
               AND member.dob IS NOT NULL AND DATE_FORMAT(member.dob, '%m-%d') = {$dateExpression}
               AND {$scope}"
        );
        return [
            'period' => $period,
            'answer' => sprintf('%s members have birthdays %s within your authorized scope.', number_format($total), $label),
        ];
    }

    private function detectDomain(string $question): string
    {
        if (preg_match('/\b(help|examples?|what can you|how can you)\b/', $question)) return 'help';
        if (preg_match('/\b(payment|payments|paid|giving|offering|collection|collections|money|amount|received|income)\b/', $question)) return 'payments';
        if (preg_match('/\b(attendance|present|worshippers|attendees)\b/', $question)) return 'attendance';
        if (preg_match('/\b(health|medical|clinic)\b/', $question)) return 'health';
        if (preg_match('/\b(birthday|birthdays|born)\b/', $question)) return 'birthdays';
        if (preg_match('/\b(event|events|programme|programmes)\b/', $question)) return 'events';
        if (preg_match('/\b(member|members|membership|catechumen|adherent|junior|distant|community)\b/', $question)) return 'membership';
        return 'unknown';
    }

    private function detectPeriod(string $question, string $domain): ?string
    {
        $patterns = [
            'last_year' => '/\blast year\b/', 'this_year' => '/\b(this|current) year\b/',
            'last_month' => '/\blast month\b/', 'this_month' => '/\b(this|current) month\b/',
            'last_week' => '/\blast week\b/', 'this_week' => '/\b(this|current) week\b/',
            'yesterday' => '/\byesterday\b/', 'tomorrow' => '/\btomorrow\b/',
            'today' => '/\b(today|daily)\b/', 'overall' => '/\b(overall|all time|total ever)\b/',
            'upcoming' => '/\b(upcoming|future|next event)\b/',
        ];
        foreach ($patterns as $period => $pattern) {
            if (preg_match($pattern, $question)) return $period;
        }
        if ($domain === 'birthdays') return 'today';
        if ($domain === 'events') return 'upcoming';
        return 'overall';
    }

    private function helpAnswer(): string
    {
        $suggestions = $this->suggestions();
        if (!$suggestions) return 'No dashboard data domains are currently available to your account.';
        return 'You can ask questions such as: ' . implode('; ', $suggestions) . '.';
    }

    private function suggestions(): array
    {
        $items = [];
        if (!empty($this->permissions['payments'])) $items[] = 'How much was received this month?';
        if (!empty($this->permissions['attendance'])) $items[] = 'What was attendance this week?';
        if (!empty($this->permissions['membership'])) $items[] = 'How many active members are there?';
        if (!empty($this->permissions['health'])) $items[] = 'How many health records were added this year?';
        if (!empty($this->permissions['events'])) $items[] = 'How many upcoming events are there?';
        if (!empty($this->permissions['birthdays'])) $items[] = 'How many birthdays are today?';
        return array_slice($items, 0, 6);
    }

    private function memberScope(string $alias): string
    {
        if ($this->superAdmin) return '1=1';
        if ($this->classIds !== null || $this->organizationIds !== null) {
            $parts = [];
            if ($this->classIds) $parts[] = $alias . '.class_id IN (' . implode(',', $this->classIds) . ')';
            if ($this->organizationIds) {
                $parts[] = 'EXISTS (SELECT 1 FROM member_organizations insight_org'
                    . ' WHERE insight_org.member_id = ' . $alias . '.id'
                    . ' AND insight_org.organization_id IN (' . implode(',', $this->organizationIds) . '))';
            }
            return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
        }
        return $this->churchId ? $alias . '.church_id = ' . $this->churchId : '1=0';
    }

    private function churchScope(string $alias): string
    {
        if ($this->superAdmin) return '1=1';
        return $this->churchId ? $alias . '.church_id = ' . $this->churchId : '1=0';
    }

    private function sundaySchoolCount(): int
    {
        if ($this->superAdmin) {
            $scope = '1=1';
        } elseif ($this->classIds !== null || $this->organizationIds !== null) {
            $scope = $this->classIds
                ? 'child.class_id IN (' . implode(',', $this->classIds) . ')'
                : '1=0';
        } else {
            $scope = $this->churchId ? 'child.church_id = ' . $this->churchId : '1=0';
        }
        return $this->scalar(
            "SELECT COUNT(*) AS total FROM sunday_school child
             WHERE child.transferred_to_member_id IS NULL AND {$scope}"
        );
    }

    private function scalar(string $sql): int
    {
        $result = $this->conn->query($sql);
        $row = $result ? $result->fetch_assoc() : null;
        return (int) ($row['total'] ?? 0);
    }

    private function recordAudit(string $intent, string $domain, ?string $period, bool $answered): void
    {
        $allowedDomains = ['help', 'payments', 'attendance', 'health', 'membership', 'events', 'birthdays', 'unknown'];
        if (!in_array($domain, $allowedDomains, true)) $domain = 'unknown';
        $intent = mb_substr($intent, 0, 60);
        $period = $period === null ? null : mb_substr($period, 0, 30);
        $churchId = $this->churchId;
        $answeredValue = $answered ? 1 : 0;
        $stmt = $this->conn->prepare(
            'INSERT INTO dashboard_insight_query_audit'
            . ' (user_id, church_id, intent_code, data_domain, period_code, answered)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisssi', $this->userId, $churchId, $intent, $domain, $period, $answeredValue);
        $stmt->execute();
        $stmt->close();
    }

    private function normalizeIds(?array $ids): ?array
    {
        if ($ids === null) return null;
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}
