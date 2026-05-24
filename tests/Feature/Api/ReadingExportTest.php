<?php

/**
 * Tests for GET /api/readings/export.
 *
 * CSV content is captured via output buffering around sendContent() because
 * Symfony's StreamedResponse::getContent() returns false by design — the
 * callback only runs when the response is actually sent.
 */

use App\Models\Anemometer;
use App\Models\Reading;

// ---------------------------------------------------------------------------
// JSON format (default)
// ---------------------------------------------------------------------------

it('exports readings as JSON by default', function (): void {
    actingAsUser();
    Reading::factory()->count(3)->create();

    $response = $this->getJson('/api/readings/export');

    $response->assertOk();
    $response->assertJsonStructure(['count', 'results']);
    expect($response->json('count'))->toBeGreaterThanOrEqual(3);
});

it('JSON export includes all expected fields on each result', function (): void {
    actingAsUser();
    $anemometer = Anemometer::factory()->create();
    Reading::factory()->create([
        'speed' => 7.5,
        'anemometer_id' => $anemometer->id,
    ]);

    $response = $this->getJson('/api/readings/export');

    $response->assertOk();
    $first = $response->json('results.0');
    expect($first)->toHaveKeys(['id', 'speed', 'recorded_at', 'anemometer_id', 'anemometer_name', 'tags']);
    expect($first['anemometer_name'])->toBe($anemometer->name);
    expect($first['speed'])->toBe(7.5);
});

it('JSON export includes tags as an array of names', function (): void {
    actingAsUser();
    Reading::factory()->withTags(['gusty', 'steady'])->create();

    $response = $this->getJson('/api/readings/export');

    $response->assertOk();
    $tags = $response->json('results.0.tags');
    expect(collect($tags)->sort()->values()->all())->toBe(['gusty', 'steady']);
});

it('JSON export count matches number of results', function (): void {
    actingAsUser();
    Reading::factory()->count(4)->create();

    $response = $this->getJson('/api/readings/export');

    $response->assertOk();
    expect($response->json('count'))->toBe(count($response->json('results')));
});

// ---------------------------------------------------------------------------
// CSV format
// ---------------------------------------------------------------------------

it('CSV export responds with text/csv content type', function (): void {
    actingAsUser();
    Reading::factory()->count(1)->create();

    $response = $this->get('/api/readings/export?format=csv');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('CSV export sets a content-disposition attachment header', function (): void {
    actingAsUser();
    Reading::factory()->count(1)->create();

    $response = $this->get('/api/readings/export?format=csv');

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('readings.csv');
});

it('CSV export first line is the column header row', function (): void {
    actingAsUser();
    Reading::factory()->count(1)->create();

    $response = $this->get('/api/readings/export?format=csv');
    ob_start();
    $response->baseResponse->sendContent();
    $content = ob_get_clean();

    $firstLine = explode("\n", trim($content))[0];
    expect(str_getcsv($firstLine))->toBe(['id', 'speed', 'recorded_at', 'anemometer_id', 'anemometer_name', 'tags']);
});

it('CSV export contains one data row per reading', function (): void {
    actingAsUser();
    Reading::factory()->count(4)->create();

    $response = $this->get('/api/readings/export?format=csv');
    ob_start();
    $response->baseResponse->sendContent();
    $content = ob_get_clean();

    $lines = array_values(array_filter(explode("\n", trim($content))));
    expect(count($lines) - 1)->toBe(4); // minus header row
});

it('CSV export serialises tags as pipe-delimited within the cell', function (): void {
    actingAsUser();
    Reading::factory()->withTags(['gusty', 'breezy'])->create();

    $response = $this->get('/api/readings/export?format=csv');
    ob_start();
    $response->baseResponse->sendContent();
    $content = ob_get_clean();

    $lines = array_values(array_filter(explode("\n", trim($content))));
    $dataRow = str_getcsv($lines[1]); // index 0 is header
    $tagCell = $dataRow[5];           // tags is the 6th column
    expect(explode('|', $tagCell))->toContain('gusty');
    expect(explode('|', $tagCell))->toContain('breezy');
});

// ---------------------------------------------------------------------------
// Anemometer filter
// ---------------------------------------------------------------------------

it('filters JSON export to a single anemometer', function (): void {
    actingAsUser();
    $target = Anemometer::factory()->create();
    $other = Anemometer::factory()->create();
    Reading::factory()->count(3)->create(['anemometer_id' => $target->id]);
    Reading::factory()->count(2)->create(['anemometer_id' => $other->id]);

    $response = $this->getJson("/api/readings/export?anemometer={$target->id}");

    $response->assertOk();
    expect($response->json('count'))->toBe(3);
    collect($response->json('results'))->each(
        fn ($r) => expect($r['anemometer_id'])->toBe($target->id),
    );
});

it('filters CSV export to a single anemometer', function (): void {
    actingAsUser();
    $target = Anemometer::factory()->create();
    $other = Anemometer::factory()->create();
    Reading::factory()->count(2)->create(['anemometer_id' => $target->id]);
    Reading::factory()->count(3)->create(['anemometer_id' => $other->id]);

    $response = $this->get("/api/readings/export?format=csv&anemometer={$target->id}");
    ob_start();
    $response->baseResponse->sendContent();
    $content = ob_get_clean();

    $lines = array_values(array_filter(explode("\n", trim($content))));
    expect(count($lines) - 1)->toBe(2); // minus header row
});

// ---------------------------------------------------------------------------
// Tag filters (reuses existing ReadingFilter — smoke test only)
// ---------------------------------------------------------------------------

it('filters JSON export by tags_any', function (): void {
    actingAsUser();
    Reading::factory()->withTags(['gusty'])->create();
    Reading::factory()->withTags(['calm'])->create();
    Reading::factory()->create(); // untagged — should not appear

    $response = $this->getJson('/api/readings/export?tags_any=gusty,calm');

    $response->assertOk();
    expect($response->json('count'))->toBe(2);
});

// ---------------------------------------------------------------------------
// Auth and validation
// ---------------------------------------------------------------------------

it('returns 401 for unauthenticated export requests', function (): void {
    $response = $this->getJson('/api/readings/export');

    $response->assertStatus(401);
});

it('returns 422 for an unrecognised format value', function (): void {
    actingAsUser();

    $response = $this->getJson('/api/readings/export?format=xml');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['format']);
});

it('returns 422 for a non-uuid anemometer param', function (): void {
    actingAsUser();

    $response = $this->getJson('/api/readings/export?anemometer=not-a-uuid');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['anemometer']);
});
