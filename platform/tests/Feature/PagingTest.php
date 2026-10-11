<?php

namespace Tests\Feature;

use Tests\TestCase;

/** NFR-PERF-04: the lists that grow with use, by pages of 25 by default and 100 at most, with their total. */
final class PagingTest extends TestCase
{
    public function test_lists_come_by_pages_with_their_total_or_whole_as_before(): void
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin);
        $this->signIn($admin)->assertOk();

        $whole = $this->getJson('/api/referential/nodes')->assertOk()->assertJsonMissingPath('meta')->json('data');
        $this->assertCount(33, $whole);
        $page = $this->getJson('/api/referential/nodes?paginate=1')->assertOk()
            ->assertJsonPath('meta', ['total' => 33, 'per_page' => 25, 'page' => 1])->assertJsonCount(25, 'data');
        $this->getJson('/api/referential/nodes?paginate=1&page=2')->assertJsonCount(8, 'data');
        $this->assertSame(array_column($whole, 'id'), [...array_column($page->json('data'), 'id'),
            ...array_column($this->getJson('/api/referential/nodes?paginate=1&page=2')->json('data'), 'id')]);
        $this->getJson('/api/referential/nodes?paginate=1&per_page=101')->assertUnprocessable();

        // Found however written, in French or in Arabic; each with its parent's name.
        $this->getJson('/api/referential/nodes?paginate=1&q=PRIMAIRE')->assertJsonPath('data.0.code', 'PRIMAIRE');
        $first = $this->getJson('/api/referential/nodes?paginate=1&q='.urlencode('السنة الأولى ابتدائي'))->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(['PRIMAIRE', 'grade'], [$first['parent_code'], $first['type_code']]);
        $this->assertArrayHasKey('fr', $first['parent_labels']);

        $this->getJson('/api/role-assignments?paginate=1&q=admin')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/role-assignments?paginate=1&q=nobody')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/notifications?paginate=1')->assertOk()->assertJsonPath('meta.page', 1)->assertJsonPath('unread', 0);
        foreach (['/api/jobs', '/api/imports', '/api/exports', '/api/support-grants', '/api/deliveries/failed'] as $list) {
            $this->getJson($list.'?paginate=1&per_page=10')->assertOk()->assertJsonPath('meta.per_page', 10);
            $this->getJson($list)->assertOk()->assertJsonMissingPath('meta');
        }
    }
}
