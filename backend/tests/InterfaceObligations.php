<?php

declare(strict_types=1);

namespace Tests;

/**
 * `API-213` — every integrity-critical statement in CMP-DOC-10 has an automated
 * test, or says why it does not.
 *
 * CMP-DOC-10 carries **100** ‡ statements. `API-213` places one obligation on
 * this repository: *"Every integrity-critical statement in this document shall
 * have an automated contract test."* Until now the only answer to it was the
 * obligation register's note — *"the contract suite covers the operations that
 * exist"* — which is a claim about coverage rather than a check of it.
 *
 * This is the check. One row per ‡ statement, in identifier order, each carrying
 * a status and either the test that proves it or the reason nothing does.
 *
 * ## Why so many are blocked, and why that is the honest answer
 *
 * CMP-DOC-10 specifies the whole interface. The platform serves a fraction of it:
 * versions, health, configuration, two session operations, four emergency-contact
 * operations and two safety operations. A statement about seat availability,
 * payment status or a provider callback governs a resource that does not exist,
 * and no test can assert it into being.
 *
 * `blocked` is therefore the largest group, and each row names what stands in
 * the way rather than saying "not yet". A statement is **never** marked enforced
 * because a related test exists: `API-153` ‡ (payment status never settable) is
 * the clearest case — payment status is one of `API-037` ‡'s seven and is
 * refused on every request today, and the statement is still about a payment
 * resource there is none of.
 *
 * ## `absent` is the finding list
 *
 * A statement is `absent` where **its subject exists and nothing asserts it**.
 * Those are defects in this repository rather than consequences of the chain, and
 * they are the reason this register was worth writing.
 *
 * ## The identifiers are checked against the document
 *
 * `InterfaceObligationsTest` reads CMP-DOC-10 and fails the build if a statement
 * listed here is not marked ‡ there, or if a ‡ statement in the document is
 * missing from here. A register that chose its own membership would be a register
 * asserting its own completeness.
 */
final class InterfaceObligations
{
    /**
     * The five statuses, shared with every other register on the platform
     * (`CC-038`).
     *
     * `withheld` does not appear here and is deliberately still permitted: no
     * ‡ statement in CMP-DOC-10 governs a capability that must never be built,
     * and if one ever does it must be sayable.
     *
     * @var list<string>
     */
    public const STATUSES = ['enforced', 'absent', 'blocked', 'withheld', 'not_applicable'];

    /**
     * Every ‡ statement in CMP-DOC-10, with what proves it.
     *
     * @return array<string, array{status: string, provenBy: ?string, note: string}>
     */
    public static function all(): array
    {
        return [
            'API-002' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\InterfaceStructureRulesTest',
                'note' => 'test_every_operation_invokes_exactly_one_application_service, plus test_every_operation_the_platform_exposes_is_read. It was absent when this register was first written; writing the register is what found it. CC-053 then found that the rule read only six of the eleven operations — a promoted constructor ending ") {}" swallowed the method after it — and that counting "->execute(" judged the three controllers whose service takes no actor to invoke nothing. Both are corrected, and the operation set is now asserted so that "no offender" cannot mean "no subject".',
            ],
            'API-003' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\InterfaceStructureRulesTest',
                'note' => 'test_no_adapter_reads_persistence_at_all — broader than StructuralRulesTest rule 3, which catches the ORM and would pass a controller reaching the query layer directly.',
            ],
            'API-014' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\SafetyIncidentsTest',
                'note' => 'The served identifier is asserted to be thirty-two hexadecimal characters, which is DB-022 ‡’s form. The entropy behind it is DB-023 ‡’s and comes from RandomIncidentReferences; nothing here counts up to one.',
            ],
            'API-016' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No monetary amount crosses the interface. T5 (DB-032 monetary precision) was expressly excluded from the ratified technical decisions and no money column may be created until it is set.',
            ],
            'API-019' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\RestRoutingRulesTest',
                'note' => 'test_the_version_is_the_first_path_segment.',
            ],
            'API-020' => [
                'status' => 'absent',
                'provenBy' => null,
                'note' => 'Nothing compares one release of the contract with the next. What would close it is a stored contract snapshot per served version — and there is only one served version, with no preceding one (VersioningTest), so there is nothing yet to compare against.',
            ],
            'API-024' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\VersioningTest',
                'note' => 'test_an_unserved_version_receives_its_own_outcome_and_not_a_not_found, and the same on the safety surface.',
            ],
            'API-030' => [
                'status' => 'absent',
                'provenBy' => null,
                'note' => 'The same shape as API-020: moving a condition between branches is detectable only against a previous release, and there is no previous release.',
            ],
            'API-036' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\EmergencyContactsTest',
                'note' => 'test_the_representation_carries_three_fields_and_no_more, and SafetyIncidentsTest asserts the incident’s four.',
            ],
            'API-037' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\StructuralRulesTest',
                'note' => 'Rule 11, plus RequestSchemaTest::test_the_register_covers_all_seven_of_api_037.',
            ],
            'API-038' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\RequestSchemaTest',
                'note' => 'test_an_undeclared_field_refuses_the_whole_request. The safety surface is a stated exception — CC-045(b), where FRD-FR-188 ‡ governs.',
            ],
            'API-039' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\System\\AssertedAuthorityRecordingTest',
                'note' => 'Recorded end to end, and RequestSchemaTest asserts the schema half.',
            ],
            'API-040' => [
                'status' => 'absent',
                'provenBy' => null,
                'note' => 'Form is validated (ContactDetails) and state is validated inside each service, but nothing asserts the general statement — that **every** inbound value is checked against platform state whatever its origin. It needs a rule over the operations that accept a value, of which there are three.',
            ],
            'API-042' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No administrative request exists. ADM-168 records that the administrative unit cannot start without BAD-DEC-006.',
            ],
            'API-043' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\VersioningTest',
                'note' => 'test_every_response_carries_the_time_the_platform_evaluated_it.',
            ],
            'API-044' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No projection exists. ARCH-113 places them with BE-017’s aggregates, and none is built.',
            ],
            'API-049' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Every representation served today is the caller’s own. A relationship-limited one arrives with the counterparty view, which FEAT-006 blocks on BAD-DEC-022.',
            ],
            'API-050' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\AuthorisationRulesTest',
                'note' => 'test_the_adapter_never_decides_what_a_representation_discloses.',
            ],
            'API-051' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No location is held or served. GPS is FEAT-019, blocked on BAD-DEC-021 and FRD-OQ-009.',
            ],
            'API-052' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'The only contact details the platform holds are the caller’s own and their nominated contacts’. Disclosure across a relationship arrives with FEAT-006 (BAD-DEC-022).',
            ],
            'API-053' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Integration\\Persistence\\PaymentCredentialAbsenceTest',
                'note' => 'No column, and TestDataRulesTest::test_no_test_holds_a_payment_instrument_credential keeps one out of the suite too.',
            ],
            'API-054' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Nothing is shared with an unauthenticated recipient. Trip sharing is UC-049, Outlined on BAD-DEC-022 / BAD-OQ-019.',
            ],
            'API-057' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\IdempotencyKeyTest',
                'note' => 'test_every_state_changing_method_requires_a_key.',
            ],
            'API-058' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\IdempotencyKeyTest',
                'note' => 'test_the_refusal_is_an_invalid_request_and_not_a_business_refusal.',
            ],
            'API-061' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Domain\\Shared\\IdempotentOperationTest',
                'note' => 'everything_happens_inside_one_transaction.',
            ],
            'API-062' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\SafetyIncidentsTest',
                'note' => 'test_a_repeated_raise_under_one_key_produces_one_incident — true only since CC-047, which applied the registry to every state-changing operation.',
            ],
            'API-063' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Domain\\Shared\\IdempotentOperationTest',
                'note' => 'a_repeat_with_the_same_key_and_different_content_is_refused_and_does_not_overwrite.',
            ],
            'API-066' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\SafetyIncidentsTest',
                'note' => 'The safety surface registers the same middleware and goes through the same ApplicationService wrapping.',
            ],
            'API-067' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No provider callback surface exists. BAD-DEP-004 leaves the PSP unselected and CMP-DOC-10 §13 has no implementation.',
            ],
            'API-071' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_the_four_branches_are_the_only_four.',
            ],
            'API-072' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_each_branch_is_distinguishable_by_structure_alone.',
            ],
            'API-073' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_a_business_refusal_is_never_returned_as_an_internal_fault — one of API-215 ‡’s four.',
            ],
            'API-074' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_an_internal_fault_is_never_returned_as_a_business_refusal.',
            ],
            'API-075' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_a_dependency_unavailability_is_never_returned_as_a_business_refusal.',
            ],
            'API-076' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_a_dependency_unavailability_is_never_returned_as_success.',
            ],
            'API-081' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_every_registered_identifier_is_stable_and_namespaced, against ReasonIdentifiers.',
            ],
            'API-082' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_a_business_refusal_carries_a_registered_identifier_and_a_default.',
            ],
            'API-086' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\SessionOperationsTest',
                'note' => 'test_an_unauthenticated_refusal_discloses_nothing_about_the_token.',
            ],
            'API-088' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\AuthorisationRulesTest',
                'note' => 'test_a_refusal_is_recorded_evidentially_and_by_nothing_else, and LogInspectionTest exercises it.',
            ],
            'API-089' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_the_unavailable_branch_says_that_nothing_was_decided.',
            ],
            'API-092' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'test_an_internal_fault_discloses_a_correlation_identity_and_nothing_else.',
            ],
            'API-094' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\SafetyIncidentsTest',
                'note' => 'test_somebody_elses_incident_is_indistinguishable_from_one_that_does_not_exist — three routes, one answer.',
            ],
            'API-095' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\RestRoutingRulesTest',
                'note' => 'test_every_operation_outside_section_9_1_sits_behind_a_session.',
            ],
            'API-096' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\AuthorisationRulesTest',
                'note' => 'test_authorisation_happens_in_the_application_service_base, and execute() is final.',
            ],
            'API-097' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\AuthorisationRulesTest',
                'note' => 'test_the_interface_layer_never_decides_an_authorisation.',
            ],
            'API-098' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\AuthorisationRulesTest',
                'note' => 'test_a_policy_absent_of_an_operation_states_no_rule_for_it.',
            ],
            'API-099' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\AuthorisationRulesTest',
                'note' => 'test_the_authorisation_namespace_takes_nothing_from_a_request.',
            ],
            'API-101' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\System\\SessionEndpointTest',
                'note' => 'test_refresh_issues_a_new_token_in_a_header_and_not_in_the_body.',
            ],
            'API-103' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\System\\SessionEndpointTest',
                'note' => 'test_a_terminated_token_then_stops_working_over_http.',
            ],
            'API-105' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\AuthorisationRulesTest',
                'note' => 'test_no_stated_rule_depends_on_the_undecided_role_set asserts that no rule alters entitlement, and there is no operation that would.',
            ],
            'API-106' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\AuthorisationRulesTest',
                'note' => 'Refused in whole — the service raises before the domain — and recorded evidentially.',
            ],
            'API-109' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\StructuralRulesTest',
                'note' => 'Rule 9: no statement is composed outside the declared sites.',
            ],
            'API-117' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Integration\\User\\EmergencyContactsTest',
                'note' => 'test_the_read_returns_only_the_callers_own. Level 3 rather than 4, because entitlement is behaviour and TC-031 keeps that out of a contract test.',
            ],
            'API-125' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\AssertedAuthorityTest',
                'note' => 'Verification standing is one of API-037 ‡’s seven and is refused on every request; nothing writes it through the interface.',
            ],
            'API-128' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No counterparty representation exists. FEAT-006 is blocked on BAD-DEC-022 and FRD-GAP-001.',
            ],
            'API-129' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'The same: no counterparty representation to constrain.',
            ],
            'API-132' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'op_vehicles does not exist. CC-035 holds the vehicle model on BAD-DEC-005, FRD-OQ-004 and FRD-OQ-005.',
            ],
            'API-133' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Vehicle withdrawal needs the Vehicle aggregate and a booking; neither exists.',
            ],
            'API-134' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Lawful capacity is a vehicle attribute, and CC-035 holds the attribute set.',
            ],
            'API-135' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Seat availability needs the Ride aggregate; BAD-DEC-007 (booking model) is open.',
            ],
            'API-139' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Ride withdrawal needs op_rides and a confirmed booking.',
            ],
            'API-145' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Acceptance and confirmation need the Booking aggregate; BAD-DEC-007 is open.',
            ],
            'API-148' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No booking state exists to report.',
            ],
            'API-149' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Seat contention at confirmation needs op_ride_seat_allocations.',
            ],
            'API-150' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Fare is BAD-DEC-003, and T5 forbids a money column meanwhile.',
            ],
            'API-152' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'op_payments does not exist; FEAT-016 is blocked on BAD-DEP-004.',
            ],
            'API-153' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Payment status is one of API-037 ‡’s seven and is already refused on every request — but the statement is about a payment resource, and there is none.',
            ],
            'API-154' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'StructuralRulesTest rule 13 keeps the UPI application response out of every code path today; the interface half arrives with FEAT-016.',
            ],
            'API-155' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No payment to report as pending.',
            ],
            'API-156' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No verified outcome exists to inform anybody of.',
            ],
            'API-157' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Nothing caches a payment verification because there is none.',
            ],
            'API-159' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'op_trips does not exist.',
            ],
            'API-160' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'Trip state is one of API-037 ‡’s seven and is refused on every request; the resource it governs does not exist.',
            ],
            'API-161' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No position is held. FEAT-019 is blocked on BAD-DEC-021 and FRD-OQ-009.',
            ],
            'API-163' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\SafetySurfaceRulesTest',
                'note' => 'test_the_safety_surface_answers_under_its_own_prefix, and the contract test reaches it there.',
            ],
            'API-164' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\SafetyIncidentsTest',
                'note' => 'test_a_field_the_operation_does_not_want_does_not_lose_the_signal.',
            ],
            'API-165' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\SafetyIncidentsTest',
                'note' => 'Every context element carries a standing, and DB-078 ‡’s three states are asserted at level 2 in SafetyIncidentTest.',
            ],
            'API-166' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\SafetySurfaceRulesTest',
                'note' => 'test_the_safety_surface_depends_on_none_of_the_five, validated in both directions.',
            ],
            'API-167' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Integration\\Safety\\SafetyIncidentPipelineTest',
                'note' => 'test_a_queue_that_will_not_take_the_incident_does_not_lose_it.',
            ],
            'API-168' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\SafetySurfaceRulesTest',
                'note' => 'test_nothing_on_the_safety_path_is_throttled — written before any rate limiter exists, so the first one cannot reach it.',
            ],
            'API-169' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Integration\\Safety\\SafetyIncidentPipelineTest',
                'note' => 'The incident is committed before routing is attempted, and the acknowledgement rests on the commit.',
            ],
            'API-170' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\SafetySurfaceRulesTest',
                'note' => 'test_no_safety_operation_is_declared_on_the_general_surface.',
            ],
            'API-171' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Domain\\Safety\\SafetyIncidentTest',
                'note' => 'test_there_is_no_way_to_close_an_incident_without_an_outcome; no interface closes one at all.',
            ],
            'API-175' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No callback surface. BAD-DEP-004 leaves the PSP unselected.',
            ],
            'API-176' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'The same.',
            ],
            'API-177' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'The same.',
            ],
            'API-178' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'The same.',
            ],
            'API-179' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'The same.',
            ],
            'API-180' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'The same, and T5 keeps money out meanwhile.',
            ],
            'API-187' => [
                'status' => 'not_applicable',
                'provenBy' => null,
                'note' => 'It binds the **client**: the client obtains policy values from the configuration resource and embeds none. Mobile/ is empty and MOB-OQ-001 blocks it. The platform’s half — serving them — is ConfigurationTest’s.',
            ],
            'API-190' => [
                'status' => 'not_applicable',
                'provenBy' => null,
                'note' => 'It binds the client’s polling behaviour. The platform’s half is API-189, which stamps the configuration version on every response (ConfigurationDeliveryTest).',
            ],
            'API-191' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\ConfigurationRulesTest',
                'note' => 'test_no_value_outside_section_14_2_is_delivered, with DB-153 ‡ keeping a relaxing key out of the register entirely.',
            ],
            'API-194' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\SafetySurfaceRulesTest',
                'note' => 'test_the_safety_surface_reads_no_configuration, and the contract test asserts the served envelope carries no version.',
            ],
            'API-198' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Architecture\\SafetySurfaceRulesTest',
                'note' => 'The same rule as API-168 ‡; §15.2 restates it.',
            ],
            'API-201' => [
                'status' => 'blocked',
                'provenBy' => null,
                'note' => 'No verification flow exists. CC-034 records that no delivery channel is selected and the Project Owner directed that none be invented.',
            ],
            'API-204' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\System\\AssertedAuthorityRecordingTest',
                'note' => 'The recording half is end to end. Whether repetition is **treated** as abuse needs NFR-069’s tooling, which no document specifies and nothing builds.',
            ],
            'API-208' => [
                'status' => 'not_applicable',
                'provenBy' => null,
                'note' => 'The mechanism is CMP-DOC-13’s and the deployment is BAD-DEP-009’s. No transport configuration exists in this repository to assert against.',
            ],
            'API-209' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\System\\LogInspectionTest',
                'note' => 'Asserted under exercise, against the log the platform actually wrote, with the detector validated in both directions.',
            ],
            'API-214' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\RequestSchemaTest',
                'note' => 'test_a_schema_declaring_an_authoritative_field_cannot_be_used_at_all.',
            ],
            'API-215' => [
                'status' => 'enforced',
                'provenBy' => 'Tests\\Contract\\ErrorBranchTest',
                'note' => 'The four never-as-another cases are API-073 ‡ to API-076 ‡ above, each with its own test.',
            ],
        ];
    }

    /**
     * How many carry each status.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach (self::all() as $obligation) {
            $counts[$obligation['status']]++;
        }

        return $counts;
    }

    /**
     * The statements whose subject exists and which nothing asserts.
     *
     * @return list<string>
     */
    public static function unproven(): array
    {
        $unproven = [];

        foreach (self::all() as $id => $obligation) {
            if ($obligation['status'] === 'absent') {
                $unproven[] = $id;
            }
        }

        return $unproven;
    }
}
