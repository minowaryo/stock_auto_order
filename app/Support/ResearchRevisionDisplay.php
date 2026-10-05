<?php

namespace App\Support;

/** 調査候補の保存時点のスナップショットを、人が読める変更履歴にする。 */
class ResearchRevisionDisplay
{
    private const FIELDS = [
        'candidate.theme' => 'テーマ',
        'candidate.theme_relation' => 'テーマとの関係',
        'candidate.entity_role' => '企業の役割',
        'candidate.evidence_stage' => '証拠段階',
        'candidate.counter_evidence' => '反証・未確認事項',
        'candidate.counter_evidence_checked_on' => '反証の確認日',
        'candidate.status' => '候補の状態',
        'candidate.status_reason' => '状態理由',
        'candidate.needs_recheck' => '再確認の要否',
        'candidate.archived_at' => 'アーカイブ日時',
        'event.title' => '元発表名',
        'event.original_url' => '元発表URL',
        'event.original_publisher' => '元発表の発行主体',
        'event.announced_on' => '元発表日',
        'event.verification_status' => '元資料の確認状態',
        'event.verified_on' => '元資料の確認日',
        'event.merged_into_event_id' => '同一元発表の関連付け先',
        'entity.legal_name' => '法人名',
        'entity.identification_status' => '上場主体の同定状態',
        'entity.identification_note' => '同定の根拠・保留理由',
    ];

    public static function changes(array $before, array $after): array
    {
        $changes = [];
        foreach (self::FIELDS as $path => $label) {
            $old = data_get($before, $path);
            $new = data_get($after, $path);
            if ($old !== $new) {
                $changes[] = ['label' => $label, 'before' => self::value($old), 'after' => self::value($new)];
            }
        }

        foreach (['discovery_links' => '発見経路', 'entity_listings' => '上場先', 'claims' => '主張と根拠'] as $key => $label) {
            $old = self::collection($key, $before[$key] ?? []);
            $new = self::collection($key, $after[$key] ?? []);
            if ($old !== $new) {
                $changes[] = ['label' => $label, 'before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    private static function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '未記録';
        }

        if (is_bool($value)) {
            return $value ? 'はい' : 'いいえ';
        }

        return (string) $value;
    }

    private static function collection(string $key, array $items): string
    {
        if ($items === []) {
            return '未記録';
        }

        $fields = match ($key) {
            'discovery_links' => ['source_title', 'publisher', 'url', 'checked_on', 'summary', 'sponsorship_note'],
            'entity_listings' => ['market', 'symbol_code', 'listed_entity_name', 'source_url', 'confirmed_on'],
            'claims' => ['claim', 'evidence_url', 'status', 'is_primary_source', 'checked_on'],
        };

        return implode(' ／ ', array_map(
            fn (array $item) => implode('・', array_map(fn (string $field) => self::value($item[$field] ?? null), $fields)),
            $items,
        ));
    }
}
