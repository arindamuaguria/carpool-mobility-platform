<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

/**
 * Where accounts are read and written.
 *
 * `BE-037`: declared in `Domain` and returning domain objects. `BE-038` ‡: it
 * deals in whole aggregates, so there is no way to read a verification standing
 * or an account state on its own — reading half a `User` is how a caller ends up
 * making a decision the aggregate would have refused.
 *
 * ## Two lookups, and why the second exists
 *
 * A user is identified by {@see UserReference} everywhere the platform speaks
 * about them (`DB-022` ‡, `DB-024` ‡). {@see forPhoneNumber()} exists because
 * `BAD-RULE-043` makes the verified phone number the account's one mandatory
 * identifying detail and `SEC-015` makes possession of it the whole of
 * authentication — so the number is what a caller who holds no session can
 * present, and there is nothing else to find them by.
 *
 * `op_users_phone_number_unique` (integrity constraint 22) makes that lookup
 * single-valued, which is also what enforces `FRD-FR-004` and the ratified
 * `FRD-FR-013` reading: one number, one account, never two.
 *
 * ## What is deliberately absent
 *
 * There is no `delete()`. `DADR-12` removes personal data *"by nulling or
 * tokenising in place and never by deleting a row"*, and `DB-030` ‡ makes every
 * foreign key `RESTRICT` so that nothing cascades into the records a party is
 * entitled to as evidence. A method that could remove an account would be a way
 * to lose them.
 *
 * There is no query returning many users, and no search. `SEC-079` and
 * `BAD-RULE-025` bound what one person may learn about another, and a repository
 * that could list accounts is a repository an unrelated caller could be pointed
 * at. The counterparty view of `FRD-FR-027` is relationship-limited and arrives
 * with the relationship that qualifies it.
 */
interface UserRepository
{
    /**
     * The account with this reference, or null where none has it.
     */
    public function forReference(UserReference $reference): ?User;

    /**
     * The account holding this number, or null where none does.
     *
     * Null rather than an exception, for `SEC-021`'s reason: *"the platform shall
     * not disclose whether a phone number is registered in response to an
     * authentication attempt."* A contract that raised for an unregistered number
     * would make absence a different kind of event from presence at the very
     * point where the two must look alike.
     */
    public function forPhoneNumber(PhoneNumber $phoneNumber): ?User;

    /**
     * Writes a registered or amended account.
     *
     * `BE-047` ‡: opens no transaction. It is called inside the one the
     * application service opened.
     */
    public function save(User $user): void;
}
