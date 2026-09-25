<?php

function member_transfer_origin_from_input(array $input): array {
    $enabledValue = strtolower(trim((string) ($input['transfer_from_other_chapel'] ?? '')));
    $enabled = in_array($enabledValue, ['1', 'yes', 'true', 'on'], true);
    if (!$enabled) {
        return [
            'transfer_from_other_chapel' => 0,
            'transfer_diocese' => '',
            'transfer_circuit' => '',
            'transfer_society' => '',
            'removal_note_provided' => 0,
            'superintendent_name' => '',
        ];
    }

    return [
        'transfer_from_other_chapel' => 1,
        'transfer_diocese' => mb_substr(trim((string) ($input['transfer_diocese'] ?? '')), 0, 150),
        'transfer_circuit' => mb_substr(trim((string) ($input['transfer_circuit'] ?? '')), 0, 150),
        'transfer_society' => mb_substr(trim((string) ($input['transfer_society'] ?? '')), 0, 150),
        'removal_note_provided' => ((string) ($input['removal_note_provided'] ?? '0')) === '1' ? 1 : 0,
        'superintendent_name' => mb_substr(trim((string) ($input['superintendent_name'] ?? '')), 0, 150),
    ];
}

function member_transfer_origin_validation_error(array $origin): string {
    if ((int) ($origin['transfer_from_other_chapel'] ?? 0) !== 1) {
        return '';
    }

    $required = [
        'transfer_diocese' => 'Diocese',
        'transfer_circuit' => 'Circuit',
        'transfer_society' => 'Society/Chapel',
        'superintendent_name' => 'Superintendent Minister',
    ];
    $missing = [];
    foreach ($required as $key => $label) {
        if (trim((string) ($origin[$key] ?? '')) === '') {
            $missing[] = $label;
        }
    }
    if (!$missing) {
        return '';
    }
    return 'Complete the transfer origin details: ' . implode(', ', $missing) . '.';
}

function member_transfer_origin_apply(array &$member, array $origin): void {
    foreach ($origin as $key => $value) {
        $member[$key] = $value;
    }
}
