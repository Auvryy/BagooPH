<?php

namespace App\Services;

use App\Models\AccountPolicyAcceptance;
use App\Models\User;
use Illuminate\Validation\Rule;

class RegistrationPolicyService
{
    public const TERMS_VERSION = '2026-10-12';

    public const PRIVACY_VERSION = '2026-10-12';

    public function contract(): array
    {
        return [
            'required' => true,
            'terms_version' => self::TERMS_VERSION,
            'privacy_version' => self::PRIVACY_VERSION,
            'terms_url' => '/terms/'.self::TERMS_VERSION,
            'privacy_url' => '/privacy/'.self::PRIVACY_VERSION,
        ];
    }

    public function rules(): array
    {
        return [
            'policy_accepted' => ['required', 'accepted'],
            'terms_version' => ['bail', 'required', 'string', Rule::in([self::TERMS_VERSION])],
            'privacy_version' => ['bail', 'required', 'string', Rule::in([self::PRIVACY_VERSION])],
        ];
    }

    public function messages(): array
    {
        return [
            'policy_accepted.required' => 'Read and agree to the Terms of Use and acknowledge the Privacy Notice before creating your account.',
            'policy_accepted.accepted' => 'Read and agree to the Terms of Use and acknowledge the Privacy Notice before creating your account.',
            'terms_version.*' => 'The Terms of Use have changed. Reload registration, read them and agree again.',
            'privacy_version.*' => 'The Privacy Notice has changed. Reload registration, read it and acknowledge it again.',
        ];
    }

    public function record(User $user, string $source): AccountPolicyAcceptance
    {
        return AccountPolicyAcceptance::create([
            'user_id' => $user->id,
            'terms_version' => self::TERMS_VERSION,
            'privacy_version' => self::PRIVACY_VERSION,
            'source' => $source,
            'accepted_at' => now(),
        ]);
    }

    public function document(string $kind): array
    {
        return $kind === 'terms' ? [
            'title' => 'Terms of Use', 'version' => self::TERMS_VERSION,
            'introduction' => 'These terms explain how to use BagooPH accounts, shopping and delivery services. Read the Privacy Notice separately to understand how your information is used.',
            'sections' => [
                ['title' => 'Your account', 'paragraphs' => [
                    'Provide accurate details and evidence, protect your password and verification codes, and use only your own account. Your registration email remains your sign-in identity. Different roles require separate accounts.',
                    'Email verification confirms access to an inbox. Buyer identity review and worker approval remain separate requirements. Sellers, couriers and logistics applicants must be at least 18. Registration does not guarantee approval or a work assignment.',
                ]],
                ['title' => 'Selling and delivery work', 'paragraphs' => [
                    'A seller account operates one shop in its approved category. Listings, descriptions, stock and evidence must be accurate. Reviewed identity, shop scope and vehicle details use the correction process in account settings.',
                    'Couriers and logistics users may act only on work assigned through Bagoo. Follow recorded handovers, delivery evidence and the current approval and assignment rules. Do not use private buyer information for another purpose.',
                    'The platform commission is 10% of product sales and the seller share is 90%. Shipping and handling are recorded separately.',
                ]],
                ['title' => 'Purchases and order problems', 'paragraphs' => [
                    'Review the product, delivery address, available delivery option and displayed total before placing an order. Checkout currently uses cash on delivery. A wallet balance, top-up or wallet payment is not available.',
                    'Use order tracking for recorded progress. Only the buyer’s receipt confirmation completes a delivered order. Report missing, damaged or incorrect items through the order’s return or dispute controls. Eligibility and decisions depend on the recorded order and evidence; approval of every return or refund is not guaranteed.',
                    'These terms do not remove any rights that apply under Philippine law.',
                ]],
                ['title' => 'Responsible use and account restrictions', 'paragraphs' => [
                    'Do not submit false documents, access another person’s records, misuse verification codes, interfere with the service, or list prohibited items. Bagoo may restrict access under its existing review process. Records needed for unresolved orders, custody or financial obligations remain available to authorized reviewers.',
                    'Service availability and delivery times can vary. Use the information shown for your order rather than assuming a guaranteed delivery date.',
                ]],
                ['title' => 'Questions and updates', 'paragraphs' => [
                    'Use Bagoo’s Support control or your account’s review feedback to raise questions. If you cannot sign in, contact the Bagoo operator through the contact provided with your access to the service.',
                    'Registration records the version you agreed to and the time of acceptance. A later document version must not be represented as a version you already accepted.',
                ]],
            ],
        ] : [
            'title' => 'Privacy Notice', 'version' => self::PRIVACY_VERSION,
            'introduction' => 'BagooPH collects information to operate accounts, review applications, fulfil orders and resolve problems. This notice describes the current service. Acknowledging it does not sign you up for promotional messages.',
            'sections' => [
                ['title' => 'Information collected', 'paragraphs' => [
                    'Account information includes your name, registration email, password hash, contact details, optional avatar, and the personal details requested for your role. Verification uses email challenges and records their result.',
                    'Buyer records may include delivery addresses, purchases, messages, ratings, returns and identity evidence. Seller applications include shop details and identity and business evidence. Courier applications include vehicle, licence and registration evidence. Logistics applications include company, fleet and accreditation details.',
                    'Signing in with Google supplies the account identifier, name, email and available profile photo returned by Google. Bagoo uses session cookies, device access tokens and recorded security events to operate and protect access.',
                ]],
                ['title' => 'Purpose and access', 'paragraphs' => [
                    'Information is used to manage your account, confirm ownership, review identity and role eligibility, fulfil purchases and delivery assignments, send necessary account and order notices, and investigate disputes or abuse.',
                    'Sellers, assigned couriers and authorized logistics users receive the order and contact details needed for their work. Authorized administrators review applications and governance records. Private identity and licence documents use controlled document access rather than public product listings.',
                    'The service uses its hosting, storage and configured email delivery infrastructure to process these records. Service notices and operational processing are separate from optional marketing. This acknowledgement does not authorize unrelated use of your information.',
                ]],
                ['title' => 'Retention and account closure', 'paragraphs' => [
                    'Bagoo keeps active account and transaction records needed to operate the service. Closing an account restricts access but does not erase records needed for orders, custody, financial reconciliation, disputes or audit history.',
                    'There is currently no automatic retention or deletion schedule. The operator must review retention needs and applicable requirements; this notice does not promise automatic erasure after a fixed period.',
                ]],
                ['title' => 'Your choices and requests', 'paragraphs' => [
                    'You can edit ordinary contact details, avatars and future delivery addresses in your account. Reviewed legal identity and evidence use the correction review. Your original sign-in email stays fixed; additional contact or recovery emails require password confirmation and an emailed code.',
                    'You may ask about access, correction, objection, erasure or blocking of your information and raise a privacy complaint. Requests may require ownership confirmation, and some records must be retained for outstanding obligations or applicable requirements.',
                    'Use Bagoo’s Support control or contact the Bagoo operator through the contact supplied with your service access. The operator must provide its current controller identity and privacy contact before broader public operation; those details are not configured in this service yet.',
                ]],
            ],
        ];
    }
}
