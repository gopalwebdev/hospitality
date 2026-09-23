<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Vite;

abstract class TestCase extends BaseTestCase
{
    /**
     * Read built assets, never a running dev server.
     *
     * Laravel decides whether to emit dev-server script tags purely by
     * checking that `public/hot` exists — never that anything is listening on
     * that port. So a developer with `npm run dev` up had the suite render
     * `https://hospitality.test:5173/...` while CI, which builds and runs no
     * dev server, rendered hashed manifest URLs: the same test passed in CI
     * and failed locally, for a reason nothing in the test could see.
     *
     * Pointing the hot file at a path that cannot exist makes every test take
     * the manifest branch, so local and CI render the same thing whatever the
     * developer happens to be running. See `.ai/rules/js.md`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Vite::useHotFile(storage_path('framework/testing/vite-is-never-hot'));
    }
}
