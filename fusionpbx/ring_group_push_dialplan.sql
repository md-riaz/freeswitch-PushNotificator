-- Ring-group push dialplan, stored the way FusionPBX stores a dialplan:
-- one v_dialplans row (with app_uuid and description) plus its v_dialplan_details rows.
-- The row is global (domain_uuid is NULL). It shows in Dialplan Manager and can be edited there;
-- saving it in the UI rebuilds dialplan_xml from the details. dialplan_xml below is the output of
-- FusionPBX's own dialplan->xml() for these details. Keep dialplan_order at 1.
BEGIN;

INSERT INTO v_dialplans (
    dialplan_uuid, domain_uuid, app_uuid, dialplan_context, dialplan_name, dialplan_number,
    dialplan_continue, dialplan_order, dialplan_enabled, dialplan_description,
    insert_date, dialplan_xml
) VALUES (
    '30a6fe50-e391-4ec3-869d-14342bfa1bca', NULL, '29014e83-6d59-43f1-bb63-13b722ce13c2',
    'ring_group_push', 'ring_group_push', 'ring_group_push',
    'false', 1, 'true', 'Ring-group push wait for mod_apn (internal loopback leg)',
    now(),
    $xml$<extension name="ring_group_push" continue="false" uuid="30a6fe50-e391-4ec3-869d-14342bfa1bca">
	<condition field="destination_number" expression="^ring_group_push$"/>
	<condition field="${channel_name}" expression="^loopback/ring_group_push-b$"/>
	<condition field="${ring_group_push_user}" expression="^[A-Za-z0-9_.+-]+$"/>
	<condition field="${ring_group_push_domain}" expression="^[A-Za-z0-9.-]+$"/>
	<condition field="${ring_group_push_uuid}" expression="^[0-9A-Fa-f-]{36}$"/>
	<condition field="${ring_group_push_timeout}" expression="^[1-9][0-9]*$"/>
	<condition field="${ring_group_push_call_id}" expression="^[A-Za-z0-9._@-]+$">
		<action application="set" data="continue_on_fail=true"/>
		<action application="bridge" data="{ignore_early_media=true,originate_timeout=${ring_group_push_timeout}}[sip_h_X-sip_call_id=${ring_group_push_call_id},ent_originate_aleg_uuid=${ring_group_push_uuid}]apn_wait/${ring_group_push_user}@${ring_group_push_domain}"/>
		<action application="hangup" data="NO_ANSWER"/>
	</condition>
</extension>
$xml$
);

-- All details are in group 0: seven conditions, then three actions in the last condition.
-- break and inline stay NULL so the XML has no break or inline attributes.
INSERT INTO v_dialplan_details (
    domain_uuid, dialplan_uuid, dialplan_detail_uuid, dialplan_detail_tag, dialplan_detail_type,
    dialplan_detail_data, dialplan_detail_break, dialplan_detail_inline,
    dialplan_detail_group, dialplan_detail_order, dialplan_detail_enabled, insert_date
) VALUES
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', 'e3ed59b5-692e-4789-b211-96e32e409e64', 'condition', 'destination_number', '^ring_group_push$', NULL, NULL, 0, 5, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', 'ae3ffa3e-8ecd-49c6-b1e1-931067715441', 'condition', '${channel_name}', '^loopback/ring_group_push-b$', NULL, NULL, 0, 10, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', 'fb0ff4aa-5289-4f99-b784-9001376063a5', 'condition', '${ring_group_push_user}', '^[A-Za-z0-9_.+-]+$', NULL, NULL, 0, 15, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', 'c3df66f3-fd90-46f2-8c4d-0e0e3643ded4', 'condition', '${ring_group_push_domain}', '^[A-Za-z0-9.-]+$', NULL, NULL, 0, 20, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', 'b536872e-9424-4c9d-862d-19d511043e2e', 'condition', '${ring_group_push_uuid}', '^[0-9A-Fa-f-]{36}$', NULL, NULL, 0, 25, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', '9d8b537d-5d97-4acb-87e6-5f4401830130', 'condition', '${ring_group_push_timeout}', '^[1-9][0-9]*$', NULL, NULL, 0, 30, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', 'a2d115f1-ca79-4055-9d3d-8f2901bfabfd', 'condition', '${ring_group_push_call_id}', '^[A-Za-z0-9._@-]+$', NULL, NULL, 0, 35, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', '3a1bf1c9-f298-4193-bbe6-e52b93828612', 'action', 'set', 'continue_on_fail=true', NULL, NULL, 0, 40, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', '0866eb9a-6d95-462e-ab98-46adc7c6b515', 'action', 'bridge', $d${ignore_early_media=true,originate_timeout=${ring_group_push_timeout}}[sip_h_X-sip_call_id=${ring_group_push_call_id},ent_originate_aleg_uuid=${ring_group_push_uuid}]apn_wait/${ring_group_push_user}@${ring_group_push_domain}$d$, NULL, NULL, 0, 45, true, now()),
(NULL, '30a6fe50-e391-4ec3-869d-14342bfa1bca', '9d88ffc2-4e7c-4b92-95ff-510b41fdf018', 'action', 'hangup', 'NO_ANSWER', NULL, NULL, 0, 50, true, now());

COMMIT;
