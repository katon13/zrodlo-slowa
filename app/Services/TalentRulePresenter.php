<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Operatorski opis reguł Talentu. Klucze activity_type pozostają kontraktem
 * zapisu i naliczania, ale nie są tekstem interfejsu administracyjnego.
 */
final class TalentRulePresenter
{
    /** @var array<string,array{title:string,description:string,icon:string}> */
    private const GROUPS = [
        'presence' => [
            'title' => 'Start i aktywna obecność',
            'description' => 'Nagrody związane z utworzeniem konta i potwierdzoną obecnością zalogowanego użytkownika.',
            'icon' => 'sun',
        ],
        'community' => [
            'title' => 'Czytanie i społeczność',
            'description' => 'Nagrody za rzeczywistą aktywność przy treściach oraz działania społecznościowe.',
            'icon' => 'article',
        ],
        'campaigns' => [
            'title' => 'Ankiety i kampanie',
            'description' => 'Zasady wynagradzania udziału w badaniach, newsletterach i kampaniach partnerów.',
            'icon' => 'survey',
        ],
        'events' => [
            'title' => 'Materiały płatne i wydarzenia',
            'description' => 'Nagrody powiązane z materiałami PPV i potwierdzonym udziałem w transmisjach na żywo.',
            'icon' => 'video',
        ],
        'other' => [
            'title' => 'Pozostałe działania',
            'description' => 'Reguły dodatkowe rozpoznane przez system nagród.',
            'icon' => 'points',
        ],
    ];

    /** @var array<string,array{title:string,description:string,group:string,icon:string,badge?:string,tone?:string}> */
    private const RULES = [
        'registration_bonus' => [
            'title' => 'Założenie konta',
            'description' => 'Przyznawana jeden raz po prawidłowym utworzeniu konta użytkownika.',
            'group' => 'presence',
            'icon' => 'registration',
            'badge' => 'Jednorazowa',
        ],
        'day_visit_bonus' => [
            'title' => 'Aktywna wizyta dzienna',
            'description' => 'Uruchamiana dopiero po potwierdzeniu aktywnej obecności zalogowanego użytkownika. Samo otwarcie strony nie wystarcza.',
            'group' => 'presence',
            'icon' => 'sun',
            'badge' => 'Kontrola obecności',
        ],
        'login_bonus' => [
            'title' => 'Logowanie — reguła historyczna',
            'description' => 'Zachowana dla zgodności ze starszą wersją. Obecny mechanizm nie nagradza samego logowania; używa aktywnej wizyty dziennej.',
            'group' => 'presence',
            'icon' => 'login',
            'badge' => 'Nie używać w nowym modelu',
            'tone' => 'warning',
        ],
        'article_read_bonus' => [
            'title' => 'Przeczytanie artykułu',
            'description' => 'Przyznawana po weryfikacji czasu czytania i postępu w treści, nie za samo wejście na artykuł.',
            'group' => 'community',
            'icon' => 'article',
            'badge' => 'Weryfikacja czytania',
        ],
        'comment_bonus' => [
            'title' => 'Dodanie komentarza',
            'description' => 'Przyznawana po zapisaniu komentarza powiązanego z konkretną treścią.',
            'group' => 'community',
            'icon' => 'comment',
        ],
        'share_bonus' => [
            'title' => 'Udostępnienie treści',
            'description' => 'Przyznawana za zarejestrowane udostępnienie konkretnej treści.',
            'group' => 'community',
            'icon' => 'share',
        ],
        'link_click_bonus' => [
            'title' => 'Kliknięcie promowanego linku',
            'description' => 'Przyznawana za zarejestrowane kliknięcie w obsługiwany link.',
            'group' => 'community',
            'icon' => 'cursor',
        ],
        'like_bonus' => [
            'title' => 'Polubienie treści',
            'description' => 'Przyznawana za polubienie przypisane do użytkownika i konkretnej treści.',
            'group' => 'community',
            'icon' => 'like',
        ],
        'bug_report_bonus' => [
            'title' => 'Zgłoszenie błędu',
            'description' => 'Nagroda za zarejestrowane zgłoszenie pomagające poprawić działanie serwisu.',
            'group' => 'community',
            'icon' => 'bug',
        ],
        'survey_reward' => [
            'title' => 'Udział w ankiecie',
            'description' => 'Przyznawana po zapisaniu kompletnej odpowiedzi w aktywnej ankiecie.',
            'group' => 'campaigns',
            'icon' => 'survey',
        ],
        'sponsored_article_read_bonus' => [
            'title' => 'Przeczytanie treści sponsorowanej',
            'description' => 'Przyznawana za potwierdzone przeczytanie materiału w ramach aktywnej kampanii.',
            'group' => 'campaigns',
            'icon' => 'article',
        ],
        'ad_view_reward' => [
            'title' => 'Obejrzenie materiału reklamowego',
            'description' => 'Przyznawana za zarejestrowane obejrzenie materiału w aktywnej kampanii.',
            'group' => 'campaigns',
            'icon' => 'eye',
        ],
        'ad_click_reward' => [
            'title' => 'Kliknięcie reklamy',
            'description' => 'Przyznawana za potwierdzone kliknięcie reklamy przypisanej do kampanii.',
            'group' => 'campaigns',
            'icon' => 'cursor',
        ],
        'newsletter_open_reward' => [
            'title' => 'Otwarcie wiadomości od redakcji',
            'description' => 'Przyznawana, gdy system pocztowy przekaże potwierdzone otwarcie obsługiwanej wiadomości.',
            'group' => 'campaigns',
            'icon' => 'mail',
        ],
        'ppv_reward' => [
            'title' => 'Udział w materiale PPV',
            'description' => 'Przyznawana po zarejestrowaniu udziału użytkownika w materiale płatnym.',
            'group' => 'events',
            'icon' => 'video',
        ],
        'live_event_reward' => [
            'title' => 'Udział w wydarzeniu na żywo',
            'description' => 'Przyznawana za potwierdzone dołączenie do aktywnego wydarzenia live.',
            'group' => 'events',
            'icon' => 'video',
        ],
    ];

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{key:string,title:string,description:string,icon:string,rules:list<array<string,mixed>>}>
     */
    public static function groups(array $rows): array
    {
        $grouped = [];
        foreach (self::GROUPS as $key => $group) {
            $grouped[$key] = $group + ['key' => $key, 'rules' => []];
        }

        foreach ($rows as $row) {
            $type = ActivityUiHelper::normalizeType((string)($row['activity_type'] ?? ''));
            $definition = self::RULES[$type] ?? self::fallbackDefinition($type);
            $group = isset($grouped[$definition['group']]) ? $definition['group'] : 'other';
            $grouped[$group]['rules'][] = $row + [
                'operator_title' => $definition['title'],
                'operator_description' => $definition['description'],
                'operator_icon' => $definition['icon'],
                'operator_badge' => $definition['badge'] ?? null,
                'operator_tone' => $definition['tone'] ?? 'default',
            ];
        }

        return array_values(array_filter(
            $grouped,
            static fn(array $group): bool => $group['rules'] !== []
        ));
    }

    /** @return array{title:string,description:string,group:string,icon:string} */
    private static function fallbackDefinition(string $type): array
    {
        $label = ActivityUiHelper::getLabel($type, 'pl');
        if ($label === '' || $label === $type) {
            $label = ucfirst(str_replace('_', ' ', preg_replace('/_(bonus|reward)$/', '', $type) ?? $type));
        }

        return [
            'title' => $label,
            'description' => 'Dodatkowa reguła aktywności obsługiwana przez system nagród.',
            'group' => 'other',
            'icon' => ActivityUiHelper::getIconName($type),
        ];
    }
}
