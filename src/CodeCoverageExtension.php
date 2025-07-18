<?php

/**
 * This file is part of the friends-of-phpspec/phpspec-code-coverage package.
 *
 * @author ek9 <dev@ek9.co>
 * @license MIT
 *
 * For the full copyright and license information, please see the LICENSE file
 * that was distributed with this source code.
 */

declare(strict_types=1);

namespace FriendsOfPhpSpec\PhpSpec\CodeCoverage;

use FriendsOfPhpSpec\PhpSpec\CodeCoverage\Exception\NoCoverageDriverAvailableException;
use FriendsOfPhpSpec\PhpSpec\CodeCoverage\Listener\CodeCoverageListener;
use PhpSpec\Console\ConsoleIO;
use PhpSpec\Extension;
use PhpSpec\ServiceContainer;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report;
use SebastianBergmann\CodeCoverage\Report\Thresholds;
use SebastianBergmann\CodeCoverage\Version;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function count;
use function is_array;

/**
 * Injects Code Coverage Event Subscriber into the EventDispatcher.
 * The Subscriber will add Code Coverage information before each example.
 *
 * @author Henrik Bjornskov
 */
class CodeCoverageExtension implements Extension
{
    /**
     * @param array<string, string> $params
     */
    public function load(ServiceContainer $container, array $params = []): void
    {
        foreach ($container->getByTag('console.commands') as $command) {
            $command->addOption('no-coverage', null, InputOption::VALUE_NONE, 'Skip code coverage generation');
        }

        $container->define('code_coverage.filter', static function () {
            return new Filter();
        });

        $container->define('code_coverage', static function (ServiceContainer $container) {
            /** @var Filter $filter */
            $filter = $container->get('code_coverage.filter');

            try {
                return new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
            } catch (RuntimeException $error) {
                throw new NoCoverageDriverAvailableException(
                    'There is no available coverage driver to be used.',
                    0,
                    $error
                );
            }
        });

        $container->define('code_coverage.options', static function (ServiceContainer $container) use ($params) {
            $options = !empty($params) ? $params : $container->getParam('code_coverage');

            if (!isset($options['format'])) {
                $options['format'] = ['html'];
            } elseif (!is_array($options['format'])) {
                $options['format'] = (array) $options['format'];
            }

            if (isset($options['output'])) {
                if (!is_array($options['output']) && 1 === count($options['format'])) {
                    $format = $options['format'][0];
                    $options['output'] = [$format => $options['output']];
                }
            }

            if (!isset($options['show_uncovered_files'])) {
                $options['show_uncovered_files'] = true;
            }

            if (!isset($options['lower_upper_bound'])) {
                $options['lower_upper_bound'] = 35;
            }

            if (!isset($options['high_lower_bound'])) {
                $options['high_lower_bound'] = 70;
            }

            if (!isset($options['show_only_summary'])) {
                $options['show_only_summary'] = false;
            }

            return new CodeCoverageOptions($options);
        });

        $container->define('code_coverage.reports', static function (ServiceContainer $container) {
            /** @var CodeCoverageOptions $optionsWrapper */
            $optionsWrapper = $container->get('code_coverage.options');
            $options = $optionsWrapper->getOptions();

            $reports = [];

            foreach ($optionsWrapper->getFormats() as $format) {
                switch ($format) {
                    case 'clover':
                        $reports['clover'] = new Report\Clover();
                        break;
                    case 'php':
                        $reports['php'] = new Report\PHP();
                        break;
                    case 'text':
                        $reports['text'] = version_compare(Version::id(), '10.0.0', '>=') && class_exists(Thresholds::class)
                            ? new Report\Text(
                                Thresholds::from($optionsWrapper->getLowerUpperBound(), $optionsWrapper->getHighLowerBound()),
                                $optionsWrapper->showUncoveredFiles(), // @phpstan-ignore-line Version 10.0.0+ uses Thresholds
                                $optionsWrapper->showOnlySummary()
                            )
                            : new Report\Text(
                                $optionsWrapper->getLowerUpperBound(),
                                $optionsWrapper->getHighLowerBound(),
                                $optionsWrapper->showUncoveredFiles(),
                                $optionsWrapper->showOnlySummary()
                            );
                        break;
                    case 'xml':
                        $reports['xml'] = new Report\Xml\Facade(Version::id());
                        break;
                    case 'crap4j':
                        $reports['crap4j'] = new Report\Crap4j();
                        break;
                    case 'html':
                        $reports['html'] = new Report\Html\Facade();
                        break;
                    case 'cobertura':
                        $reports['cobertura'] = new Report\Cobertura();
                        break;
                }
            }

            $container->setParam('code_coverage', $options);

            return new CodeCoverageReports($reports);
        });

        $container->define('event_dispatcher.listeners.code_coverage', static function (ServiceContainer $container) {
            /** @var InputInterface $input */
            $input = $container->get('console.input');
            $skipCoverage = $input->hasOption('no-coverage') && $input->getOption('no-coverage');

            /** @var ConsoleIO $consoleIO */
            $consoleIO = $container->get('console.io');

            /** @var CodeCoverage $codeCoverage */
            $codeCoverage = $container->get('code_coverage');

            /** @var CodeCoverageReports $codeCoverageReportsWrapper */
            $codeCoverageReportsWrapper = $container->get('code_coverage.reports');

            /** @var CodeCoverageOptions $optionsWrapper */
            $optionsWrapper = $container->get('code_coverage.options');

            $listener = new CodeCoverageListener(
                $consoleIO,
                $codeCoverage,
                $codeCoverageReportsWrapper->getReports(),
                $skipCoverage
            );
            $listener->setOptions($optionsWrapper->getOptions());

            return $listener;
        }, ['event_dispatcher.listeners']);
    }
}
