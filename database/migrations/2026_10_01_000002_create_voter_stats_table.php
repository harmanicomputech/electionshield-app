<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voter figures for the Voter intelligence page (registered voters, PVC
 * collection, gender, age, occupation, first-time voters...) for Nigeria,
 * the state, an LGA, a ward or a PU. Every figure carries its source.
 *
 * Seeded with the figures INEC published for the 2023 register (national
 * breakdowns and Ebonyi's total); nothing is estimated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_stats', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10);
            $table->string('area', 190)->default('');
            $table->string('dimension', 30);
            $table->string('category', 60);
            $table->unsignedBigInteger('count')->nullable();
            $table->decimal('percent', 6, 2)->nullable();
            $table->string('source', 190);
            $table->string('source_url', 500)->nullable();
            $table->date('as_of')->nullable();
            $table->string('note', 300)->nullable();
            $table->string('added_by', 100)->nullable();
            $table->timestamps();
            $table->unique(['level', 'area', 'dimension', 'category']);
            $table->index('source');
        });

        $inec2023 = [
            'source' => 'INEC 2023 register, presented 11 Jan 2023 (Premium Times)',
            'source_url' => 'https://www.premiumtimesng.com/news/headlines/575140-2023-polls-youth-population-tops-age-distribution-chart-as-inec-presents-list-of-93-4-registered-voters.html',
            'as_of' => '2023-01-11',
        ];
        $national = [
            ['registered', 'total', 93469008, null],
            ['gender', 'male', 49054162, 52.5],
            ['gender', 'female', 44414846, 47.5],
            ['age', '18-34', 37060399, 39.65],
            ['age', '35-49', 33413591, 35.75],
            ['age', '50-69', 17700270, 18.94],
            ['age', '70+', 5294748, 5.66],
            ['occupation', 'student', 26027481, 27.8],
            ['occupation', 'farmer_fisher', 14742554, 15.8],
            ['occupation', 'housewife', 13006939, 13.9],
        ];
        $now = now();
        $rows = array_map(fn ($row) => [
            'level' => 'national', 'area' => '', 'dimension' => $row[0], 'category' => $row[1], 'count' => $row[2], 'percent' => $row[3],
            ...$inec2023, 'note' => null, 'added_by' => 'Built in', 'created_at' => $now, 'updated_at' => $now,
        ], $national);
        $rows[] = [
            'level' => 'state', 'area' => '', 'dimension' => 'registered', 'category' => 'total', 'count' => 1597646, 'percent' => null,
            'source' => 'INEC 2023 register, Ebonyi total (as reported on Wikipedia)', 'source_url' => 'https://en.wikipedia.org/wiki/2023_Nigerian_presidential_election_in_Ebonyi_State', 'as_of' => '2023-01-11',
            'note' => null, 'added_by' => 'Built in', 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('voter_stats')->insert($rows);

        // Coordinators can see the page (admins have everything anyway).
        if (Schema::hasTable('roles')) {
            $row = DB::table('roles')->where('key', 'coordinator')->first();
            if ($row) {
                $permissions = json_decode($row->permissions, true) ?: [];
                if (! in_array('view_voter_intelligence', $permissions, true)) {
                    $permissions[] = 'view_voter_intelligence';
                    DB::table('roles')->where('id', $row->id)->update(['permissions' => json_encode($permissions)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_stats');
    }
};
