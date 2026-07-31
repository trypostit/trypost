<?php

declare(strict_types=1);

use App\Enums\Invite\Result;

test('invite results map to the expected flash banner and style', function (Result $result, string $bannerKey, string $style) {
    expect($result->flashBanner())->toBe(__($bannerKey));
    expect($result->flashStyle())->toBe($style);
})->with([
    'wrong email' => [Result::WrongEmail, 'settings.members.flash.wrong_email', 'danger'],
    'gone' => [Result::Gone, 'settings.members.flash.invite_workspace_gone', 'danger'],
    'already accepted' => [Result::AlreadyAccepted, 'settings.members.flash.already_member', 'info'],
    'already member' => [Result::AlreadyMember, 'settings.members.flash.already_member', 'info'],
    'accepted' => [Result::Accepted, 'settings.members.flash.invite_accepted', 'success'],
    'declined' => [Result::Declined, 'settings.members.flash.invite_declined', 'info'],
]);
