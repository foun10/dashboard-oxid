<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Unit\Double;

use DateTimeImmutable;
use foun10\Dashboard\Core\DashboardData;
use foun10\Dashboard\Core\Period;
use LogicException;

/**
 * DashboardData with its shop seams replaced: queries are answered from canned results
 * (matched by a substring of the SQL) and recorded, the cache is an array, and config values
 * are plain properties.
 */
final class TestableDashboardData extends DashboardData
{
    /** @var array<int, array{needle: string, result: mixed}> */
    private $answers = [];

    /** @var array<int, array{method: string, sql: string, params: array}> */
    public $queries = [];

    /** @var array<string, mixed> */
    public $cache = [];

    /** @var array<string, int> */
    public $cacheTtls = [];

    /** @var array<string, mixed> */
    public $config = [];

    /** @var int */
    public $shopId = 1;

    /** @var int */
    public $languageId = 0;

    /** @var string */
    public $now = '2026-09-17 13:05:00';

    /**
     * Answers every query containing $needle with $result; earlier registrations win.
     *
     * @param mixed $result
     */
    public function on(string $needle, $result): self
    {
        $this->answers[] = ['needle' => $needle, 'result' => $result];

        return $this;
    }

    /**
     * @return array<int, array{method: string, sql: string, params: array}>
     */
    public function queriesContaining(string $needle): array
    {
        return array_values(array_filter($this->queries, static function (array $query) use ($needle): bool {
            return strpos($query['sql'], $needle) !== false;
        }));
    }

    public function callCollect(Period $period): array
    {
        return $this->collect($period);
    }

    public function callGetOrderTotals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->getOrderTotals($from, $to);
    }

    public function callGetTrend(Period $period): array
    {
        return $this->getTrend($period);
    }

    public function callToBreakdown(array $rows, float $totalRevenue): array
    {
        return $this->toBreakdown($rows, $totalRevenue);
    }

    public function callGetStockStatus(?float $stock, int $soldOutVariants): string
    {
        return $this->getStockStatus($stock, $soldOutVariants);
    }

    public function callGetOpenBaskets(): array
    {
        return $this->getOpenBaskets();
    }

    public function callGetCustomerTypes(Period $period, float $totalRevenue): array
    {
        return $this->getCustomerTypes($period, $totalRevenue);
    }

    public function callGetPayments(Period $period, float $totalRevenue): array
    {
        return $this->getPayments($period, $totalRevenue);
    }

    public function callGetCountries(Period $period, float $totalRevenue): array
    {
        return $this->getCountries($period, $totalRevenue);
    }

    public function callGetCacheTtl(): int
    {
        return $this->getCacheTtl();
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        return (array) $this->answer('all', $sql, $params, []);
    }

    protected function fetchRow(string $sql, array $params = []): array
    {
        return (array) $this->answer('row', $sql, $params, []);
    }

    protected function fetchOne(string $sql, array $params = [])
    {
        return $this->answer('one', $sql, $params, false);
    }

    protected function readCache(string $key)
    {
        return $this->cache[$key] ?? null;
    }

    protected function writeCache(string $key, array $data): void
    {
        $this->cache[$key] = $data;
        $this->cacheTtls[$key] = $this->getCacheTtl();
    }

    protected function getConfigBool(string $name): bool
    {
        return (bool) ($this->config[$name] ?? false);
    }

    protected function getModuleSettingString(string $name): string
    {
        return (string) ($this->config[$name] ?? '');
    }

    protected function getShopUrl(string $articleId): string
    {
        return 'https://shop.test/' . $articleId . '.html';
    }

    protected function getViewName(string $table): string
    {
        return 'oxv_' . $table . '_' . $this->languageId;
    }

    protected function getLanguageId(): int
    {
        return $this->languageId;
    }

    protected function getShopId(): int
    {
        return $this->shopId;
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->now);
    }

    protected function getDb()
    {
        throw new LogicException('The unit suite must not reach the database.');
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function answer(string $method, string $sql, array $params, $default)
    {
        $this->queries[] = ['method' => $method, 'sql' => $sql, 'params' => $params];

        foreach ($this->answers as $answer) {
            if (strpos($sql, $answer['needle']) !== false) {
                return $answer['result'];
            }
        }

        return $default;
    }
}
