<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Services\MasterCategoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MasterCategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (MasterCategoryService::NAMES as $name) {
                $roots = Category::whereNull('parent_id')->where('name', $name)->lockForUpdate()->get();
                if ($roots->isNotEmpty()) {
                    if ($roots->count() > 1) {
                        $this->command?->warn("Duplicate master roots need review: {$name}. Existing rows were preserved.");
                    }

                    continue;
                }
                $base = Str::slug($name);
                $slug = $base;
                $suffix = 0;
                while (Category::where('slug', $slug)->exists()) {
                    $slug = $base.'-master'.(++$suffix > 1 ? '-'.$suffix : '');
                }
                Category::create(['name' => $name, 'slug' => $slug, 'parent_id' => null, 'is_active' => true]);
            }
        });
    }
}
