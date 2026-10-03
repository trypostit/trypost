<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->migration = require collect(glob(database_path('migrations/*_make_time_format_required_on_users_table.php')))->sole();
    $this->seededUsers = [];
});

afterEach(function () {
    $column = collect(Schema::getColumns('users'))->firstWhere('name', 'time_format');

    if (data_get($column, 'nullable') === true) {
        DB::table('users')->whereNull('time_format')->update(['time_format' => TimeFormat::DEFAULT->value]);
        $this->migration->up();
    }

    $accountIds = DB::table('users')->whereIn('id', $this->seededUsers)->pluck('account_id')->all();
    DB::table('users')->whereIn('id', $this->seededUsers)->delete();
    DB::table('accounts')->whereIn('id', $accountIds)->delete();
});

test('backfills a missing time format from the language and keeps chosen ones', function () {
    $this->migration->down();

    $english = User::factory()->create(['locale' => Locale::English]);
    $german = User::factory()->create(['locale' => Locale::German]);
    $japanese = User::factory()->create(['locale' => Locale::Japanese]);
    $chosen = User::factory()->create(['locale' => Locale::English, 'time_format' => TimeFormat::TwentyFourHour]);
    $this->seededUsers = [$english->id, $german->id, $japanese->id, $chosen->id];
    DB::table('users')->whereIn('id', [$english->id, $german->id, $japanese->id])->update(['time_format' => null]);

    $this->migration->up();

    expect($english->fresh()->time_format)->toBe(TimeFormat::TwelveHour)
        ->and($german->fresh()->time_format)->toBe(TimeFormat::TwentyFourHour)
        ->and($japanese->fresh()->time_format)->toBe(TimeFormat::TwentyFourHour)
        ->and($chosen->fresh()->time_format)->toBe(TimeFormat::TwentyFourHour);
});

test('the time format column is required', function () {
    $column = collect(Schema::getColumns('users'))->firstWhere('name', 'time_format');
    $user = User::factory()->create();

    expect(data_get($column, 'nullable'))->toBeFalse()
        ->and($user->fresh()->time_format)->toBe(TimeFormat::DEFAULT)
        ->and(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['time_format' => null])))->toThrow(QueryException::class);
});
