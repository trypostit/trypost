<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

/**
 * Why Google refused a review reply, grouped from the reply's policyViolation
 * into what the user can act on. Google may add values, so an unknown one
 * reads as the generic policy reason.
 *
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews#policyviolation
 */
enum ReviewRejectionReason: string
{
    case ProfileRestricted = 'profile_restricted';
    case ProfileNotVerified = 'profile_not_verified';
    case OwnershipRevoked = 'ownership_revoked';
    case Promotional = 'promotional';
    case Spam = 'spam';
    case Offensive = 'offensive';
    case PersonalInfo = 'personal_info';
    case Unsafe = 'unsafe';
    case Misleading = 'misleading';
    case ModerationError = 'moderation_error';
    case Policy = 'policy';

    /** @var array<string, self> */
    public const array GOOGLE_VALUES = [
        'POLICY_VIOLATION_UNSPECIFIED' => self::Policy,
        'ACCOUNT_RESTRICTED_AND_SUSPENDED' => self::ProfileRestricted,
        'ACCOUNT_RESTRICTED_AND_EDIT_REJECTED' => self::ProfileRestricted,
        'ASSOCIATED_ACCOUNT_SUSPENDED' => self::ProfileRestricted,
        'BUSINESS_INELIGIBLE' => self::ProfileRestricted,
        'OWNERSHIP_REVOKED' => self::OwnershipRevoked,
        'LOCATION_SUSPENDED' => self::ProfileRestricted,
        'LOCATION_DISABLED' => self::ProfileRestricted,
        'LOCATION_VERIFICATION_REQUIRED' => self::ProfileNotVerified,
        'PO_BOX' => self::ProfileRestricted,
        'GOOGLE_PAY_SUSPENSION' => self::ProfileRestricted,
        'EDITING_RESTRICTION' => self::ProfileRestricted,
        'ILLEGAL_BUSINESS_HOURS_EDIT' => self::ProfileRestricted,
        'FAKE_ENGAGEMENT' => self::Policy,
        'ADULT_THEMED' => self::Offensive,
        'SEXUALLY_EXPLICIT' => self::Offensive,
        'ADVERTISING_AND_SOLICITATION' => self::Promotional,
        'DANGEROUS' => self::Unsafe,
        'GIBBERISH_AND_REPETITIVE' => self::Spam,
        'REPETITIVE' => self::Spam,
        'UNCLEAR' => self::Spam,
        'ILLEGAL' => self::Unsafe,
        'OFF_TOPIC' => self::Spam,
        'OFFENSIVE' => self::Offensive,
        'VIOLENCE_AND_GORE' => self::Offensive,
        'CHILD_SAFETY' => self::Unsafe,
        'HARASSMENT' => self::Offensive,
        'HATE_SPEECH' => self::Offensive,
        'IMPERSONATION' => self::Misleading,
        'MISINFORMATION' => self::Misleading,
        'MISREPRESENTATION' => self::Misleading,
        'OBSCENITY_AND_PROFANITY' => self::Offensive,
        'PERSONAL_INFO' => self::PersonalInfo,
        'REGULATED_GOODS_AND_SERVICES' => self::Unsafe,
        'GENERIC_VANDALISM' => self::Spam,
        'GENERIC_FRAUD' => self::Misleading,
        'PHOTOS_AND_VIDEOS_CRITERIA' => self::Policy,
        'MODERATION_ERROR' => self::ModerationError,
        'PRIVACY' => self::PersonalInfo,
        'GENERIC_VIOLATION' => self::Policy,
        'GENERIC_QUALITY' => self::Spam,
        'IDENTITY_CHANGE' => self::ProfileRestricted,
        'GEOGRAPHIC_LOCATION_CHANGE' => self::ProfileRestricted,
    ];

    public static function fromGoogle(?string $violation): self
    {
        return self::GOOGLE_VALUES[(string) $violation] ?? self::Policy;
    }

    public function message(): string
    {
        return __("inbox.review.rejection.{$this->value}");
    }
}
