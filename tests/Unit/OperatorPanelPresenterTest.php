<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Dors3OperatorPresenter;
use App\Services\TalentRulePresenter;
use PHPUnit\Framework\TestCase;

final class OperatorPanelPresenterTest extends TestCase
{
    public function testTalentRulesAreGroupedAndUseOperatorLabels(): void
    {
        $groups = TalentRulePresenter::groups([
            $this->rule('day_visit_bonus'),
            $this->rule('login_bonus'),
            $this->rule('article_read_bonus'),
            $this->rule('ad_click_reward'),
        ]);

        $rules = [];
        foreach ($groups as $group) {
            foreach ($group['rules'] as $rule) {
                $rules[(string)$rule['activity_type']] = $rule;
            }
        }

        self::assertSame('Aktywna wizyta dzienna', $rules['day_visit_bonus']['operator_title']);
        self::assertStringContainsString('aktywną wizytę zalogowanego użytkownika', $rules['day_visit_bonus']['operator_description']);
        self::assertArrayNotHasKey('login_bonus', $rules);
        self::assertSame('Przeczytanie artykułu', $rules['article_read_bonus']['operator_title']);
        self::assertSame('Kliknięcie reklamy', $rules['ad_click_reward']['operator_title']);
        self::assertSame('DZIAŁA', $rules['day_visit_bonus']['operator_readiness']);
        self::assertFalse($rules['article_read_bonus']['operator_activation_locked']);
        self::assertFalse($rules['ad_click_reward']['operator_activation_locked']);
    }

    public function testUnknownTalentRuleIsNotShownAsADeadOperatorEntry(): void
    {
        $groups = TalentRulePresenter::groups([$this->rule('special_editor_reward')]);

        self::assertSame([], $groups);
    }

    public function testTalentRulePresenterUsesRequestedAdminLanguage(): void
    {
        $groups = TalentRulePresenter::groups([
            $this->rule('day_visit_bonus'),
            $this->rule('response_publication_bonus'),
            $this->rule('ad_click_reward'),
        ], 'en');

        self::assertSame('Getting started and active presence', $groups[0]['title']);
        self::assertSame('Active daily visit', $groups[0]['rules'][0]['operator_title']);
        self::assertSame('Reading and community', $groups[1]['title']);
        self::assertSame('Published opinion or response', $groups[1]['rules'][0]['operator_title']);
        self::assertSame('Surveys and campaigns', $groups[2]['title']);
        self::assertSame('Advertisement click', $groups[2]['rules'][0]['operator_title']);
        self::assertSame('WORKING', $groups[2]['rules'][0]['operator_readiness']);
    }

    public function testTalentRuleCatalogIsCompleteForEverySupportedLanguage(): void
    {
        foreach (['pl', 'en', 'de', 'fr', 'it', 'es'] as $language) {
            $groups = TalentRulePresenter::groups([
                $this->rule('registration_bonus'),
                $this->rule('day_visit_bonus'),
                $this->rule('article_read_bonus'),
                $this->rule('response_publication_bonus'),
                $this->rule('bug_report_bonus'),
                $this->rule('survey_reward'),
                $this->rule('ad_view_reward'),
                $this->rule('ad_click_reward'),
            ], $language);

            self::assertCount(3, $groups, $language);
            foreach ($groups as $group) {
                self::assertStringNotContainsString('admin.talent.', $group['title'], $language);
                self::assertStringNotContainsString('admin.talent.', $group['description'], $language);
                foreach ($group['rules'] as $rule) {
                    foreach (['operator_title', 'operator_description', 'operator_readiness', 'operator_trigger'] as $field) {
                        self::assertStringNotContainsString('admin.talent.', (string)$rule[$field], $language . ':' . $field);
                        self::assertNotSame('', trim((string)$rule[$field]), $language . ':' . $field);
                    }
                    if ($rule['operator_badge'] !== null) {
                        self::assertStringNotContainsString('admin.talent.', (string)$rule['operator_badge'], $language . ':badge');
                    }
                }
            }
        }
    }

    public function testEveryConfiguredTalentRuleHasAnOperatorDescription(): void
    {
        $types = [
            'registration_bonus', 'day_visit_bonus', 'article_read_bonus',
            'response_publication_bonus', 'bug_report_bonus', 'survey_reward',
            'ad_view_reward', 'ad_click_reward',
        ];
        $groups = TalentRulePresenter::groups(array_map(fn(string $type): array => $this->rule($type), $types));
        $presented = [];
        foreach ($groups as $group) {
            foreach ($group['rules'] as $rule) {
                $presented[(string)$rule['activity_type']] = $rule;
            }
        }

        self::assertSame($types, array_values(array_filter(
            $types,
            static fn(string $type): bool => isset($presented[$type])
        )));
        foreach ($types as $type) {
            self::assertNotSame($type, $presented[$type]['operator_title']);
            self::assertStringNotContainsString('_', (string)$presented[$type]['operator_title']);
            self::assertNotSame('', trim((string)$presented[$type]['operator_description']));
            self::assertNotSame('', trim((string)$presented[$type]['operator_readiness']));
            self::assertNotSame('', trim((string)$presented[$type]['operator_trigger']));
            self::assertIsBool($presented[$type]['operator_activation_locked']);
        }
        self::assertFalse($presented['survey_reward']['operator_activation_locked']);
        self::assertSame('DZIAŁA', $presented['survey_reward']['operator_readiness']);
    }

    public function testHistoricalAndUnimplementedRulesStayOutOfTheOperatorPanel(): void
    {
        $hidden = ['login_bonus', 'share_bonus', 'link_click_bonus', 'like_bonus',
            'sponsored_article_read_bonus', 'newsletter_open_reward', 'ppv_reward', 'live_event_reward'];

        self::assertSame([], TalentRulePresenter::groups(array_map(
            fn(string $type): array => $this->rule($type),
            $hidden
        )));
    }

    public function testDors3SecurityEventIsTranslatedForOperator(): void
    {
        $event = Dors3OperatorPresenter::event([
            'action' => 'security.login.new_context',
            'resource_type' => 'user',
            'result' => 'warning',
            'reason' => 'new_ip_and_device',
            'risk_level' => 'medium',
        ]);

        self::assertSame('Logowanie z nowego miejsca lub urządzenia', $event['action_label']);
        self::assertSame('Konto użytkownika', $event['resource_label']);
        self::assertSame('Sprawdź', $event['result_label']);
        self::assertSame('Nowy adres sieciowy i nowe urządzenie', $event['reason_label']);
        self::assertSame('Wymaga uwagi', $event['risk_label']);
    }

    public function testDors3ReadinessUsesOperationalInstructions(): void
    {
        $items = Dors3OperatorPresenter::readiness([
            'primary_key_tested' => false,
            'recovery_codes_confirmed' => true,
        ]);

        self::assertSame('Podstawowy klucz został dodany i sprawdzony', $items[0]['label']);
        self::assertFalse($items[0]['passed']);
        self::assertSame('Potwierdzono bezpieczne zapisanie kodów awaryjnych', $items[1]['label']);
        self::assertTrue($items[1]['passed']);
        self::assertSame('Ochrona hasłem — przygotowanie do kluczy', Dors3OperatorPresenter::modeLabel('prepare'));
        self::assertSame('Hasło administratora', Dors3OperatorPresenter::confirmationLabel('password'));
    }

    /** @return array<string,mixed> */
    private function rule(string $type): array
    {
        return [
            'activity_type' => $type,
            'points_amount' => 0,
            'amount_minor' => 0,
            'daily_limit' => 0,
            'is_active' => 0,
        ];
    }
}
