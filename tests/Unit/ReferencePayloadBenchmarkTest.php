<?php

namespace Tests\Unit;

use App\Support\ReferencePayloadBenchmark;
use Tests\TestCase;

class ReferencePayloadBenchmarkTest extends TestCase
{
    /**
     * @return void
     */
    public function test_warehouse_row_includes_savings_fields(): void
    {
        $rows = ReferencePayloadBenchmark::runWarehouses([2]);
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertArrayHasKey('bytes_saving_percent', $r);
        $this->assertArrayHasKey('time_saved_seconds', $r);
        $this->assertGreaterThan(0, $r['bytes_saving_percent']);
        $this->assertGreaterThan(0, $r['reference_json_bytes']);
        $this->assertGreaterThan($r['reference_json_bytes'], $r['full_json_bytes']);
    }

    /**
     * @return void
     */
    public function test_message_templates_row_shows_payload_savings_vs_full_content(): void
    {
        $rows = ReferencePayloadBenchmark::runMessageTemplates([5]);
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertGreaterThan($r['reference_json_bytes'], $r['full_json_bytes']);
        $this->assertGreaterThan(50, (float) $r['bytes_saving_percent']);
    }

    /**
     * @return void
     */
    public function test_projects_row_shows_payload_savings_vs_full_files_and_description(): void
    {
        $rows = ReferencePayloadBenchmark::runProjects([5]);
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertGreaterThan($r['reference_json_bytes'], $r['full_json_bytes']);
        $this->assertGreaterThan(5, (float) $r['bytes_saving_percent']);
    }

    /**
     * @return void
     */
    public function test_tasks_row_shows_payload_savings_vs_full_description_and_attachments(): void
    {
        $rows = ReferencePayloadBenchmark::runTasks([5]);
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertGreaterThan($r['reference_json_bytes'], $r['full_json_bytes']);
        $this->assertGreaterThan(10, (float) $r['bytes_saving_percent']);
    }
}
