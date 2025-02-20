<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Translation;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Translation\Translator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\Snippet\SnippetService;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Formatter\MessageFormatterInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator as SymfonyTranslator;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @internal
 *
 * @covers \Shopware\Core\Framework\Adapter\Translation\Translator
 */
class TranslatorTest extends TestCase
{
    /**
     * @dataProvider getCatalogueRequestProvider
     */
    public function testGetCatalogueIsCachedCorrectly(?string $snippetSetId, ?Request $request, ?string $expectedCacheKey, ?string $injectSalesChannelId = null): void
    {
        $decorated = $this->createMock(SymfonyTranslator::class);
        $originCatalogue = new MessageCatalogue('en-GB', [
            'messages' => [
                'global.title' => 'This is a title',
                'global.summary' => 'This is a summary',
            ],
        ]);

        $decorated->expects(static::any())->method('getCatalogue')->with('en-GB')->willReturn($originCatalogue);
        $decorated->expects(static::any())->method('getLocale')->willReturn('en-GB');

        $requestStack = new RequestStack();

        if ($request !== null) {
            $requestStack->push($request);
        }

        $cache = $this->createMock(CacheInterface::class);

        $snippetServiceMock = $this->createMock(SnippetService::class);

        if ($expectedCacheKey !== null) {
            $snippetServiceMock->expects(static::once())->method('getStorefrontSnippets')->willReturn([
                'global.title' => 'This is overrided title',
                'global.description' => 'Description',
            ]);
        } else {
            $snippetServiceMock->expects(static::never())->method('getStorefrontSnippets');
        }

        $localeCodeProvider = $this->createMock(LanguageLocaleCodeProvider::class);
        $localeCodeProvider->expects(static::any())->method('getLocaleForLanguageId')->with(Defaults::LANGUAGE_SYSTEM)->willReturn('en-GB');

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $translator = new Translator(
            $decorated,
            $requestStack,
            $cache,
            $this->createMock(MessageFormatterInterface::class),
            'prod',
            $connection,
            $localeCodeProvider,
            $snippetServiceMock,
            false
        );

        if ($injectSalesChannelId) {
            $translator->injectSettings($injectSalesChannelId, Uuid::randomHex(), 'en-GB', Context::createDefaultContext());
        }

        $snippetSetIdProp = (new \ReflectionClass($translator))->getProperty('snippetSetId');
        $snippetSetIdProp->setAccessible(true);
        $snippetSetIdProp->setValue($translator, $snippetSetId);

        // No snippet is added
        if ($expectedCacheKey === null) {
            $catalogue = $translator->getCatalogue('en-GB');

            static::assertSame($originCatalogue, $catalogue);

            return;
        }

        $item = new CacheItem();
        $property = (new \ReflectionClass($item))->getProperty('isTaggable');
        $property->setAccessible(true);
        $property->setValue($item, true);

        $cache->expects(static::once())->method('get')->willReturnCallback(function (string $key, callable $callback) use ($expectedCacheKey, $item) {
            static::assertEquals($expectedCacheKey, $key);

            /** @var callable(CacheItem): mixed $callback */
            return $callback($item);
        });

        $catalogue = $translator->getCatalogue('en-GB');

        static::assertNotSame($originCatalogue, $catalogue);
        static::assertSame([
            'global.title' => 'This is overrided title',
            'global.summary' => 'This is a summary',
            'global.description' => 'Description',
        ], $catalogue->all('messages'));
    }

    /**
     * @return iterable<string, array<int, string|Request|null>>
     */
    public static function getCatalogueRequestProvider(): iterable
    {
        $salesChannelId = Uuid::randomHex();
        $snippetSetId = Uuid::randomHex();

        yield 'without request' => [
            $snippetSetId,
            null,
            sprintf('translation.catalog.%s.%s', 'DEFAULT', $snippetSetId),
        ];
        yield 'without snippetSetId' => [
            null,
            self::createRequest($salesChannelId, null),
            null,
        ];

        yield 'without salesChannelId' => [
            $snippetSetId,
            self::createRequest(null, $snippetSetId),
            sprintf('translation.catalog.%s.%s', 'DEFAULT', $snippetSetId),
        ];

        yield 'with injectSettings' => [
            $snippetSetId,
            null,
            sprintf('translation.catalog.%s.%s', $salesChannelId, $snippetSetId),
            $salesChannelId, // Inject salesChannelId using injectSettings method
        ];
    }

    /**
     * @param array<string> $tags
     *
     * @dataProvider provideTracingExamples
     */
    public function testTracing(bool $enabled, array $tags): void
    {
        $translator = new Translator(
            $this->createMock(SymfonyTranslator::class),
            new RequestStack(),
            $this->createMock(CacheInterface::class),
            $this->createMock(MessageFormatterInterface::class),
            'prod',
            $this->createMock(Connection::class),
            $this->createMock(LanguageLocaleCodeProvider::class),
            $this->createMock(SnippetService::class),
            $enabled
        );

        $translator->trace('foo', function () use ($translator) {
            return $translator->trans('foo');
        });

        static::assertSame(
            $tags,
            $translator->getTrace('foo')
        );
    }

    public static function provideTracingExamples(): \Generator
    {
        yield 'disabled' => [
            false,
            [
                'shopware.translator',
            ],
        ];

        yield 'enabled' => [
            true,
            [
                'translator.foo',
            ],
        ];
    }

    private static function createRequest(?string $salesChannelId, ?string $snippetSetId): Request
    {
        return new Request(
            [],
            [],
            [
                SalesChannelRequest::ATTRIBUTE_DOMAIN_SNIPPET_SET_ID => $snippetSetId,
                PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID => $salesChannelId,
            ]
        );
    }
}
