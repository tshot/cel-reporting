<?php
namespace CEL\Projects\iKMC\Facade;

use CEL\Projects\iKMC\Aggregator\AggregatorRegistry;
use CEL\Projects\iKMC\Infrastructure\IkmcMysqlClient;
use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * iKMC ReportFacade
 *
 * Project-specific orchestration for the iKMC reports. Follows the same
 * pattern as the Emollient ReportFacade — implements ProjectReportFacadeInterface
 * if the engine needs cross-project consistency.
 *
 * The facade hides the data-source detail from the engine: today MySQL via
 * IkmcMysqlClient, tomorrow REDCap via RedcapApiClient. The engine calls
 * createClient() and stream() the same way.
 */
class ReportFacade
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Create the data client based on config['driver'].
     * Today: mysql_pdo. Tomorrow: redcap_api (after AWS migration).
     */
    public function createClient(): IkmcMysqlClient
    {
        $driver = $this->config['driver'] ?? 'mysql_pdo';

        return match($driver) {
            'mysql_pdo'  => new IkmcMysqlClient($this->config),
            'redcap_api' => throw new \RuntimeException(
                'REDCap driver not yet wired — once AWS REDCap is live, '
                . 'wrap RedcapApiClient to match IkmcMysqlClient interface.'
            ),
            default      => throw new \InvalidArgumentException("Unknown driver: {$driver}"),
        };
    }

    public function createAggregator(string $alias, array $reportConfig): AggregatorInterface
    {
        $pk = $this->config['primary_key'] ?? 'recordid';
        return AggregatorRegistry::create($alias, $pk, $reportConfig);
    }
}
