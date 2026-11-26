BEGIN;

INSERT INTO v_dialplans (
    dialplan_uuid, dialplan_name, dialplan_number, dialplan_context,
    dialplan_order, dialplan_enabled, dialplan_continue, dialplan_xml
) VALUES (
    '30a6fe50-e391-4ec3-869d-14342bfa1bca', 'ring_group_push',
    'ring_group_push', 'ring_group_push', 1, 'true', 'false',
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
</extension>$xml$
);

COMMIT;
