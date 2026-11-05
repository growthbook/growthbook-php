<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

/**
 * Every section of the shared spec (tests/cases.json) is either run by a test or
 * excluded here with a reason, so a new section in a spec update can't be skipped
 * without anyone noticing. The data providers read each section by name with
 * getCases() and ignore the ones they don't ask for. Nested sections are listed
 * as `parent.child`.
 */
final class SpecCoverageTest extends TestCase
{
    /**
     * Sections the tests run, and the test that runs each one
     */
    private const RUN_SECTIONS = [
        'evalCondition' => 'GrowthbookTest::testEvalCondition',
        'pruneCondition' => 'GrowthbookTest::testPruneCondition',
        'reducePayload' => 'GrowthbookTest::testReducePayload',
        'hash' => 'GrowthbookTest::testHash',
        'getBucketRange' => 'GrowthbookTest::testGetBucketRange',
        'feature' => 'GrowthbookTest::testFeature',
        'run' => 'GrowthbookTest::testRun',
        'chooseVariation' => 'GrowthbookTest::testChooseVariation',
        'getQueryStringOverride' => 'GrowthbookTest::testGetQueryStringOverride',
        'inNamespace' => 'GrowthbookTest::testInNamespace',
        'getEqualWeights' => 'GrowthbookTest::testGetEqualWeights',
        'decrypt' => 'GrowthbookTest::testDecrypt',
        'stickyBucket' => 'GrowthbookTest::testStickyBucket',
        'savedGroupReferencesV2.evalCondition' => 'GrowthbookTest::testEvalCondition',
        'savedGroupReferencesV2.feature' => 'GrowthbookTest::testFeature',
        'savedGroupReferencesV2.run' => 'GrowthbookTest::testRun',
        'savedGroupReferencesRemote.evalCondition' => 'GrowthbookTest::testEvalCondition',
        'savedGroupReferencesRemote.feature' => 'GrowthbookTest::testFeature',
    ];

    /**
     * Sections the tests don't run, and why
     */
    private const EXCLUDED_SECTIONS = [
        'urlRedirect' => 'URL redirect experiments are a browser feature',
        'contextualBandit' => "Contextual bandits aren't implemented",
    ];

    /**
     * @return string[]
     */
    private static function specSections(): array
    {
        $json = file_get_contents(__DIR__ . '/cases.json');
        if (!$json) {
            throw new \Exception('Could not load cases.json');
        }
        $sections = [];
        foreach (json_decode($json, true) as $name => $value) {
            if (!is_array($value)) {
                continue; // specVersion
            }
            // array_is_list needs PHP 8.1
            if ($value === [] || array_keys($value) === range(0, count($value) - 1)) {
                $sections[] = $name;
            } else {
                foreach (array_keys($value) as $child) {
                    $sections[] = "$name.$child";
                }
            }
        }
        return $sections;
    }

    public function testEverySpecSectionIsRunOrExcluded(): void
    {
        $unaccounted = array_diff(self::specSections(), array_keys(self::RUN_SECTIONS), array_keys(self::EXCLUDED_SECTIONS));
        $this->assertSame([], array_values($unaccounted), 'Run these spec sections or exclude them with a reason');
    }

    public function testListedSectionsExistInTheSpec(): void
    {
        $listed = array_merge(array_keys(self::RUN_SECTIONS), array_keys(self::EXCLUDED_SECTIONS));
        $this->assertSame([], array_values(array_diff($listed, self::specSections())), "These sections aren't in cases.json");
    }

    public function testEveryRunSectionIsReadByATest(): void
    {
        $source = '';
        foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
            $source .= file_get_contents($file);
        }
        foreach (array_keys(self::RUN_SECTIONS) as $section) {
            $this->assertStringContainsString("getCases(\"$section\"", $source, "No test reads $section");
        }
    }

    public function testNoSectionIsBothRunAndExcluded(): void
    {
        $this->assertSame([], array_values(array_intersect(array_keys(self::RUN_SECTIONS), array_keys(self::EXCLUDED_SECTIONS))));
    }

    public function testEveryExcludedSectionHasAReason(): void
    {
        foreach (self::EXCLUDED_SECTIONS as $section => $reason) {
            $this->assertNotSame('', trim($reason), "$section needs a reason");
        }
    }
}
