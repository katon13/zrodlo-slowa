<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Security\ArticleSubmissionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArticleSubmissionPolicyTest extends TestCase
{
    public function testAcceptsContentAtDeclaredLimits(): void
    {
        self::expectNotToPerformAssertions();
        ArticleSubmissionPolicy::validate([
            'title' => str_repeat('T', ArticleSubmissionPolicy::TITLE_MAX_CHARACTERS),
            'lead' => str_repeat('L', ArticleSubmissionPolicy::LEAD_MAX_CHARACTERS),
            'body' => str_repeat('B', ArticleSubmissionPolicy::BODY_MAX_CHARACTERS),
        ]);
    }

    /** @return iterable<string,array{array<string,string>}> */
    public static function invalidContent(): iterable
    {
        yield 'empty body' => [['title' => 'Tytuł', 'body' => '']];
        yield 'title too long' => [['title' => str_repeat('T', 241), 'body' => 'Treść']];
        yield 'lead too long' => [['title' => 'Tytuł', 'lead' => str_repeat('L', 2001), 'body' => 'Treść']];
        yield 'body too long' => [['title' => 'Tytuł', 'body' => str_repeat('B', 500001)]];
    }

    #[DataProvider('invalidContent')]
    public function testRejectsMissingOrOversizedContent(array $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArticleSubmissionPolicy::validate($content);
    }
}
