# FusionPBX ring-group push integration

## What it does and why

In a FusionPBX ring group with the `simultaneous` strategy, a member who has a VoIP push token gets an extra call leg. That leg sends the push notification through `apn_wait`, waits for the phone to register, and then rings it next to the member's direct SIP leg.

The push runs in a loopback leg because `apn_wait` blocks for up to 30 seconds (`APN_MAX_TIMELIMIT_SEC`) while it waits for the phone to register. Inside a comma-joined (simultaneous) originate, that wait would delay the other legs. The loopback runs the wait in its own session.

Only members with a `voip` row in `push_tokens` get a loopback leg. Without that check, every member of every group would start a loopback and a push event, even members with no app.

The loopback leg sets `process_cdr=false`, so it does not write fake CDRs or missed calls tied to the extension.

The repository provides two files:

- `fusionpbx/ring-group-push.patch`: a `diff -u` against FusionPBX's `app/ring_groups/index.lua`. The base file already contained this repository's older legacy edit, which appended `:_:apn_wait/<ext>@<domain>` to the dial strings of registered members. Other strategies keep that legacy path.
- `fusionpbx/ring_group_push_dialplan.sql`: one `v_dialplans` row with context and number `ring_group_push` and the fixed UUID `30a6fe50-e391-4ec3-869d-14342bfa1bca`, plus its ten `v_dialplan_details` rows, stored the way FusionPBX stores a dialplan (fixed `app_uuid`, description). It validates the call variables, runs `bridge` to `apn_wait/<user>@<domain>`, and then hangs up with `NO_ANSWER`. There is no Lua helper.

The patch was made against FusionPBX 5.3.4.

## Requirements

- FusionPBX with the ring-groups app, and FreeSWITCH 1.10.x.
- `mod_apn` and `mod_loopback` loaded.
- `push_tokens` in the FusionPBX database. `index.lua` reads the table through FusionPBX's own database handle (`Database.new('system')`). The default `odbc_db=fusionpbx` satisfies this. If the table is missing, or `mod_apn` stores tokens in another database, the push legs are skipped without an error message.
- A working push server (`fcm_push.php` or similar) configured in `apn.conf.xml`.

## Install

Run the shell steps as root on the FreeSWITCH host. The examples use `S` for the active script.

### 1. Pre-checks

```sh
fs_cli -x 'module_exists mod_apn'         # true
fs_cli -x 'module_exists mod_loopback'    # true
grep -n 'odbc_db\|odbc_host' /etc/freeswitch/vars.xml
S=/usr/share/freeswitch/scripts/app/ring_groups/index.lua
md5sum $S
grep -n ':_:apn_wait' $S                  # the legacy line the patch replaces
```

In the FusionPBX database, check that tokens exist and that the context, number and UUID are unused:

```sql
select realm, type, count(*) from push_tokens group by 1, 2;

select dialplan_uuid, dialplan_context, dialplan_number from v_dialplans
 where dialplan_context = 'ring_group_push' or dialplan_number = 'ring_group_push'
    or dialplan_uuid = '30a6fe50-e391-4ec3-869d-14342bfa1bca';    -- expect 0 rows
```

Back up the files you will change:

```sh
B=$(mktemp -d "$HOME/ring-group-push.XXXXXX")
cp -p $S $B/index.before.lua
cp -p /etc/freeswitch/autoload_configs/apn.conf.xml $B/apn.conf.before.xml
# copy the two files from the fusionpbx/ directory of this repository into $B
```

### 2. Add the dialplan row

Add the row before you patch the script, so that no push leg can exist without its route.

```sh
psql -h <db-host> -U <fusionpbx-db-user> -d fusionpbx -f $B/ring_group_push_dialplan.sql
rm -f /var/cache/fusionpbx/dialplan.ring_group_push
```

The row is global (`domain_uuid` is NULL). To find it in the FusionPBX web UI, log in as a superadmin and open Dialplans > Dialplan Manager. The default list shows it, because a superadmin sees global rows. You can also type `ring_group_push` in the search box, pick the `ring_group_push` entry in the context drop-down, or press Show All. Domain admins without the `dialplan_global` permission do not see global rows, as with every other global dialplan.

Open the row to edit it. Saving it in the UI rebuilds `dialplan_xml` from the detail rows, so edit the details and not the XML by hand. The rebuilt XML has the same conditions, actions and attributes as the SQL file, with one trailing newline. Keep `Order` at 1 so that no global dialplan runs on the loopback leg, and clear the cache after each change (`rm -f /var/cache/fusionpbx/dialplan.ring_group_push`).

### 3. Route test

Send a push leg for a fake user that has no token. No phone rings and no push goes out.

```sh
fs_cli -x 'show calls count'    # choose a quiet moment so the log is easy to read
fs_cli -x "originate {process_cdr=false,ring_group_push_user=99999999,ring_group_push_domain=<your-domain>,ring_group_push_timeout=5,ring_group_push_uuid=$(cat /proc/sys/kernel/random/uuid),ring_group_push_call_id=route-test,origination_caller_id_number=0000000}loopback/ring_group_push/ring_group_push/XML &park"
grep -E 'apn_wait/99999999|No one token found.*99999999|ring_group_push-b' /var/log/freeswitch/freeswitch.log | tail -5
```

The log must show the `bridge` to `apn_wait/99999999@<your-domain>`, the line `No one token found`, and `loopback/ring_group_push-b [NO_ANSWER]`. If no `bridge` line appears, the route did not match. Check the row and the cache file before you continue.

The row must have the lowest `dialplan_order` in its context (`1` here, FusionPBX global dialplans start at `10`). Then the FusionPBX global dialplans do not run on the loopback leg and cannot rewrite the caller's exported `X-` headers. Every exported `sip_h_X-*` header reaches the mobile INVITE unchanged.

### 4. Patch the ring-group script

Patch a copy, check it, and then install it:

```sh
W=$(mktemp -d); cp -p $S $W/index.lua
cd $W && patch -p1 --dry-run < $B/ring-group-push.patch && patch -p1 < $B/ring-group-push.patch
```

If the dry run fails, do not force the patch. Your FusionPBX version differs from the one the patch targets. Port the single hunk by hand into the branch that handles registered members, next to the `sofia_contact` line. Keep these parts: the `push_tokens` lookup, `push_token ~= nil` in the condition, the comma-joined push leg with `process_cdr=false`, the call ID fallback, and `loopback_no_pre_answer=true` on the caller.

Hosts often have no standalone Lua, so check the syntax with FreeSWITCH's own Lua:

```sh
echo "assert(loadfile('$W/index.lua')); stream:write('syntax ok')" > /tmp/rgp_syntax.lua
fs_cli -x 'lua /tmp/rgp_syntax.lua'; rm -f /tmp/rgp_syntax.lua
diff -u $S $W/index.lua        # only the simultaneous branch should change
```

Install the file as `www-data` with mode 0644:

```sh
install -o www-data -g www-data -m 0644 $W/index.lua $S.new && mv -f $S.new $S
cp -p $S $B/index.after.lua
```

FreeSWITCH reads the script on each call, so no reload is needed.

## mod_apn timeouts

Set short timeouts in the `voip` profile of `/etc/freeswitch/autoload_configs/apn.conf.xml`:

```xml
<param name="connect_timeout" value="5"/>
<param name="timeout" value="15"/>
```

`mod_apn` reads `apn.conf.xml` only when it loads, and `apn_wait` has no unload guard. Reload only while the server is idle:

```sh
fs_cli -x 'show calls count'                              # must be 0 total
fs_cli -x 'show channels' | grep -cE 'apn_wait|loopback'  # must be 0
fs_cli -x 'reload mod_apn'
fs_cli -x 'show modules' | grep apn_wait
grep mod_apn /var/log/freeswitch/freeswitch.log | tail -20   # expect "Loaded APN profile 'voip'" and no errors
```

Until the reload, the old timeouts stay active. The dialplan and script steps do not depend on this one.

## Smoke tests

Use a domain where the test extension has an app registered, so that `push_tokens` has a row for that extension and realm.

1. Mobile offline, desk phone registered: the desk phone rings after its delay, the push request carries the correct `realm`, and you can answer on the mobile.
2. Same setup, answer on the desk phone: the push leg ends. There is no cancel notification yet, so the app's own timeout ends its ringing screen.
3. Nobody answers: the group's timeout action runs.
4. A member without a token: the log shows no `ring_group_push` leg for that member.
5. CDRs: no new `v_xml_cdr` rows with `destination_number = 'ring_group_push'`.

Useful log greps: `ring_group_push`, `Fire event APN for User`, `mobile::push::response`, `APN request failed`, `CURL Error`.

## Upgrading

Installs that created the `ring_group_push` row with `dialplan_order` 99 must lower it to 1, so the FusionPBX global dialplans do not run on the loopback leg:

```sql
update v_dialplans set dialplan_order = 1
 where dialplan_uuid = '30a6fe50-e391-4ec3-869d-14342bfa1bca' and dialplan_context = 'ring_group_push';
```

```sh
rm -f /var/cache/fusionpbx/dialplan.ring_group_push
```

Installs that inserted the older row with only `dialplan_xml` filled (no `app_uuid`, no detail rows) do not show in Dialplan Manager, because the list query contains `app_uuid <> 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4'` and a NULL `app_uuid` fails that comparison. Either delete the row and run the new SQL file, or convert it in place:

```sql
begin;
update v_dialplans
   set app_uuid = '29014e83-6d59-43f1-bb63-13b722ce13c2',
       dialplan_description = 'Ring-group push wait for mod_apn (internal loopback leg)',
       insert_date = coalesce(insert_date, now())
 where dialplan_uuid = '30a6fe50-e391-4ec3-869d-14342bfa1bca' and dialplan_context = 'ring_group_push';
-- then run the INSERT INTO v_dialplan_details statement from fusionpbx/ring_group_push_dialplan.sql
commit;
```

The existing `dialplan_xml` already matches what FusionPBX builds from these details, apart from the trailing newline, so the runtime behaviour does not change. Clear the cache afterwards:

```sh
rm -f /var/cache/fusionpbx/dialplan.ring_group_push
```

## Rollback

Restore the script first, which stops new push legs at once:

```sh
cmp $S $B/index.after.lua && install -o www-data -g www-data -m 0644 $B/index.before.lua $S.rb && mv -f $S.rb $S
```

Then remove the dialplan row and the cache file:

```sql
delete from v_dialplan_details where dialplan_uuid = '30a6fe50-e391-4ec3-869d-14342bfa1bca';
delete from v_dialplans
 where dialplan_uuid = '30a6fe50-e391-4ec3-869d-14342bfa1bca' and dialplan_context = 'ring_group_push';
```

```sh
rm -f /var/cache/fusionpbx/dialplan.ring_group_push
cp -p $B/apn.conf.before.xml /etc/freeswitch/autoload_configs/apn.conf.xml   # then reload mod_apn only while idle
```

## Limits

- Only the `simultaneous` strategy gets push legs. Other strategies keep the legacy path.
- Only members in the group's own domain get a push leg. Follow-me or forward targets in other domains do not.
- There is no cancel notification. If the call is answered elsewhere before the phone registers, the app's own timeout ends the ringing screen.
- `mod_apn` logs the full push URL at DEBUG level, including the device token and caller details. Keep production logging above DEBUG.
