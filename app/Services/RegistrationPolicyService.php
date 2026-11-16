<?php

namespace App\Services;

use App\Models\AccountPolicyAcceptance;
use App\Models\User;
use Illuminate\Validation\Rule;

class RegistrationPolicyService
{
    public const TERMS_VERSION = '2026-11-17';

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
            'introduction' => 'These terms are an agreement between you and BagooPH for using its marketplace, accounts, delivery and wallet services. By creating an account or placing, selling, carrying or handling an order, you agree to them. Read the Privacy Notice separately to understand how your information is used.',
            'sections' => [
                ['title' => '1. What BagooPH is', 'paragraphs' => [
                    'BagooPH is an online marketplace. It connects buyers with independent sellers and with independent logistics companies and their riders. BagooPH is not the seller of products listed by shops, and it is not the employer of riders or hub staff of logistics companies, unless it says so for a specific listing or service.',
                    'Each sale is a contract between the buyer and the seller. Each delivery is carried out by the logistics company the buyer selects at checkout. BagooPH provides the platform, records each step, holds and reconciles recorded payments, and resolves disputes under these terms.',
                ]],
                ['title' => '2. Your account', 'paragraphs' => [
                    'Provide accurate details and evidence, protect your password and verification codes, and use only your own account. You are responsible for activity under your account until you report unauthorized use through Support. Your registration email remains your sign-in identity. Different roles require separate accounts.',
                    'Email verification confirms access to an inbox. Buyer identity review and worker approval are separate requirements. Sellers, riders and logistics applicants must be at least 18 years old. Registration does not guarantee approval or a work assignment.',
                ]],
                ['title' => '3. Buying', 'paragraphs' => [
                    'Review the product, delivery address, logistics company, delivery option and displayed total before placing an order. The total shown at checkout (items, shipping and any applied voucher) is the amount you agree to pay.',
                    'You can pay cash on delivery or with Bagoo Wallet credits where offered. For cash on delivery, pay only the recorded amount to the assigned rider or counter and keep the change you receive. Wallet credits are a stored record of value for use on BagooPH only. They are not a bank deposit, do not earn interest and cannot be withdrawn as cash except where BagooPH or applicable law provides.',
                    'Give a reachable recipient and an accurate address. Failed attempts caused by a wrong address, an unreachable recipient or a refused cash-on-delivery parcel without a valid reason may lead to return of the parcel and restrictions on future cash-on-delivery orders.',
                ]],
                ['title' => '4. Selling', 'paragraphs' => [
                    'A seller account operates one shop in its approved category. The seller is responsible for its listings: the item must be genuine, legal to sell, accurately described and photographed, in stock, and safely packed with its true weight and size. Prices shown include the item price; shipping is calculated separately at checkout.',
                    'Buyer recipient details stay hidden until the seller accepts the order. By accepting an order the seller agrees to keep that information confidential, to use it only to pack, label and fulfil that order, and is responsible for any unauthorized disclosure, retention or misuse of it.',
                    'The platform commission is 10% of product sales and the seller share is 90%, adjusted for any voucher the seller funds. Seller payouts are released after the order is completed and the collected payment is reconciled. BagooPH may hold a payout while a return, dispute or investigation on that order is open.',
                ]],
                ['title' => '5. Delivery, riders and logistics companies', 'paragraphs' => [
                    'Riders and hub staff may act only on work assigned to them through BagooPH. They must scan each recorded handover, keep the parcel sealed and safe while it is in their custody, follow the recorded route and record true delivery evidence.',
                    'A parcel is in the custody of whoever last recorded receiving it: the seller until pickup, the logistics company (its riders and hubs) from pickup until recorded delivery, and the recipient after recorded delivery. Responsibility for loss or damage follows that recorded custody.',
                    'Cash collected on delivery belongs to the recorded order until it is handed over and reconciled. The rider who collected it is responsible for it until the recorded hub receipt, and the logistics company is responsible for it after that receipt until reconciliation. Delivery times shown are estimates, not guarantees.',
                ]],
                ['title' => '6. Returns and refunds', 'paragraphs' => [
                    'A buyer may request a return within seven (7) days after recorded delivery or hub collection, through the order’s return control, with a description and photo evidence. Returns follow the recorded return route and return code; the original delivery waybill cannot be used for a return.',
                    'If the problem is the seller’s fault (wrong, damaged before pickup, defective, counterfeit or not as described), the buyer is refunded the item price and the shipping the buyer paid, and the seller’s share and the platform commission for that item are reversed.',
                    'If the problem is the logistics company’s fault (lost, damaged or tampered with while in its recorded custody), BagooPH reviews the evidence independently. When the finding is confirmed, the buyer is refunded the same way and the logistics company that held the parcel is liable for the refunded amount.',
                    'Returns are not accepted for change of mind after delivery unless the listing says so, for items damaged by the buyer after delivery, or when the evidence does not support the claim. Refunds are paid after the returned item is received and its condition is recorded, and only once the original payment has been collected and reconciled. Approval of every return or refund is not guaranteed.',
                ]],
                ['title' => '7. Who is responsible for what', 'paragraphs' => [
                    'Sellers are responsible for their products, listings, packing, the truth of their declarations and their own taxes, permits and legal obligations. Logistics companies are responsible for parcels and cash in their recorded custody and for the conduct of their riders and hub staff. Buyers are responsible for accurate order details, for being available to receive or pay, and for their own use of the products they buy.',
                    'BagooPH is responsible for operating the platform with reasonable care, for keeping accurate records of each recorded step and payment, and for applying these terms fairly. BagooPH does not make, inspect or guarantee products sold by shops, and does not guarantee that a seller, rider or logistics company will perform.',
                    'To the extent permitted by law, BagooPH is not liable for indirect, incidental or consequential losses (such as lost profits or lost opportunities), and its total liability for a claim about an order is limited to the amount paid for that order. Nothing in these terms limits liability that cannot be limited by law, including liability for fraud, gross negligence or wilful misconduct.',
                ]],
                ['title' => '8. Prohibited conduct', 'paragraphs' => [
                    'Do not list or ship illegal, counterfeit, stolen, dangerous or restricted items; submit false documents or evidence; access another person’s account or records; misuse buyer information; manipulate prices, vouchers, reviews or ratings; arrange payment outside BagooPH to avoid fees or records; or interfere with the service.',
                    'BagooPH may remove listings, withhold or reverse payouts connected to a violation, apply recorded financial penalties to accounts that breach their duties, and suspend or close accounts under its review process. Records needed for unresolved orders, custody or financial obligations stay available to authorized reviewers after an account is restricted.',
                ]],
                ['title' => '9. Indemnity', 'paragraphs' => [
                    'Sellers, logistics companies and riders agree to cover BagooPH for claims, losses and costs (including reasonable legal costs) that come from their own products, their breach of these terms, their violation of law, or the acts of their staff while handling an order.',
                ]],
                ['title' => '10. Events outside anyone’s control', 'paragraphs' => [
                    'No party is responsible for delay or failure caused by events beyond reasonable control, such as typhoons, floods, earthquakes, government orders, road closures or widespread network outages. Affected orders continue, are rerouted or are cancelled and refunded under the recorded process once the event allows.',
                ]],
                ['title' => '11. Disputes and governing law', 'paragraphs' => [
                    'Raise problems first through the order’s return, dispute or Support controls so they can be reviewed against the recorded evidence. Users agree to cooperate in good faith with that review before going to court.',
                    'These terms are governed by the laws of the Republic of the Philippines, including the Consumer Act (Republic Act No. 7394), the Electronic Commerce Act (Republic Act No. 8792), the Internet Transactions Act (Republic Act No. 11967) and the Data Privacy Act (Republic Act No. 10173). These terms do not remove any rights you have under those laws. If a part of these terms is found invalid, the rest stays in effect.',
                ]],
                ['title' => '12. Changes and questions', 'paragraphs' => [
                    'BagooPH may update these terms. Each version has a date. Registration records the version you agreed to and when; a later version is never shown as one you already accepted. Material changes are announced in the service before they apply to new orders.',
                    'Use BagooPH’s Support control or your account’s review feedback for questions. If you cannot sign in, contact the BagooPH operator through the contact provided with your access to the service.',
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
