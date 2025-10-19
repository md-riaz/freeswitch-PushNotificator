# Freeswitch PushNotification module (mod_apn)
Push notifications module for FreeSWITCH, designed for deployments such as [FusionPBX](https://www.fusionpbx.com/).

> **This guide assumes FreeSWITCH is already installed and running. You may either rebuild FreeSWITCH to include this module or compile and install the module separately on an existing system.**
---

## Overview

`mod_apn` listens to `sofia::register` events, parses the `Contact` header from SIP REGISTER, and stores device push notification tokens (VoIP and IM) into your database. When a call is generated to a user with a stored token, the endpoint `apn_wait` sends an HTTP request to your push server (FCM/APNs) with all info regarding the device.

Expired or invalid tokens are now reported with HTTP 410 responses from `fcm_push.php`, and `mod_apn` will automatically remove those entries from the `push_tokens` table when APNs returns the same status.

---

## Dependencies

- `libcurl`

## Prerequisites

1. **Discover your FreeSWITCH version and modules directory:**
   ```sh
   freeswitch -version
   ```

2. **Install build prerequisites (dev headers only):**
   ```sh
   sudo apt-get update
   sudo apt-get install -y git build-essential autoconf automake libtool pkg-config \
     freeswitch-dev
   # freeswitch-dev provides headers & pkg-config needed to build modules
   ```

---

## Credential setup

### Firebase service-account.json
To authenticate a service account and authorize it to access Firebase services, you must generate a private key file in JSON format.

To generate a private key file for your service account:
1. In the [Firebase console](https://console.firebase.google.com/project/_/settings/serviceaccounts/adminsdk), open **Settings > Service Accounts**.
2. Click **Generate New Private Key**, then confirm by clicking **Generate Key**.
3. Securely store the JSON file containing the key and place it alongside `fcm_push.php` as `service-account.json`.

### Apple APNs auth key
1. Sign in to the [Apple Developer portal](https://developer.apple.com/).
2. In **Certificates, Identifiers & Profiles**, create a new key and enable **Apple Push Notifications service (APNs)**.
3. Download the resulting `AuthKey_<KEY_ID>.p8` file and rename it to `AuthKey.p8`, placing it alongside `fcm_push.php`.
4. Record the **Key ID** and your **Team ID**; configure `APNS_KEY_ID`, `APNS_TEAM_ID`, and `APNS_BUNDLE_ID` constants in `fcm_push.php` with these values and your VoIP bundle identifier.
5. For sandbox testing, change the `APNS_HOST` constant in `fcm_push.php` to `api.development.push.apple.com`.

### Push token parameters

The SIP `Contact` header and push requests use distinct fields so each notification type can provide its own token:

- `pn-voip-tok` – The token used for VoIP push notifications.
- `pn-im-tok` – The token used for instant messaging (IM) push notifications.
- `pn-platform` – The device platform (lowercase ios, android, etc.).

Use `type=voip` for incoming call events so the module selects `pn-voip-tok` and wakes the app for CallKit or a foreground service.

Use `type=im` for chat or text notifications so `pn-im-tok` is delivered instead.

### Example server payloads

#### Android (FCM)
```json
{
  "to": "<ANDROID_FCM_REG_TOKEN>",
  "priority": "high",
  "data": {
    "type": "voip",
    "cid_number": "1234567890",
    "cid_name": "Alice"
  }
}
```

#### iOS (APNs VoIP)
```json
{
  "cid_number": "1234567890",
  "cid_name": "Alice"
}
```

## Build and Install mod_apn

1. **Clone and register the module:**
   ```sh
   mkdir -p /usr/src && cd /usr/src/
   git clone https://github.com/md-riaz/freeswitch-PushNotificator.git PushNotification
   sudo cp -a ./PushNotification/mod_apn /usr/src/freeswitch/src/mod/endpoints/

   cd /usr/src/freeswitch
   # Add module to build list if not already present
   echo 'endpoints/mod_apn' >> modules.conf
   ```

   > **Tip:** You are only editing the local source tree. Do not touch or overwrite your live FreeSWITCH installation.

2. **Re-bootstrap and configure the build system:**
   ```sh
   cd /usr/src/freeswitch
   # Add to configure.ac configuration for create Makefile for mod_apn (AC_CONFIG_FILES array section)
   sed -i '/src\/mod\/endpoints\/mod_sofia\/Makefile/a src\/mod\/endpoints\/mod_apn\/Makefile' configure.ac
   autoreconf -fvi
   ./configure
   ```

### Option A: Rebuild FreeSWITCH with mod_apn

Rebuild and install the entire FreeSWITCH tree with the new module:

```sh
cd /usr/src/freeswitch
make
sudo make install
```

### Option B: Build only the module

Compile and install just `mod_apn` without rebuilding the rest of FreeSWITCH:

```sh
cd /usr/src/freeswitch
make mod_apn
sudo make mod_apn-install
# or from inside the module directory:
cd src/mod/endpoints/mod_apn
make && sudo make install
```

---
## Sanity Check

Make sure the module was installed (adjust directory if different):
```sh
ls /usr/lib/freeswitch/mod | grep mod_apn || \
ls /usr/lib/x86_64-linux-gnu/freeswitch/mod | grep mod_apn
```

---

## Configuration
Change `apn.conf.xml` with your configuration of the push-server URL and related parameters.

```sh
sudo cp /usr/src/PushNotification/conf/autoload_configs/apn.conf.xml /etc/freeswitch/autoload_configs/

# If `make mod_apn-install` or `make install` did not copy the module automatically
sudo cp /usr/src/freeswitch/src/mod/endpoints/mod_apn/.libs/mod_apn.so /usr/lib/freeswitch/mod/
```

### Module configuration
```xml
<settings>
    <!-- Connection string to db. mod_apn will create table push_tokens with schema:
    "CREATE TABLE push_tokens ("
        "id             serial NOT NULL,"
        "token          VARCHAR(255) NOT NULL,"
        "extension      VARCHAR(255) NOT NULL,"
        "realm          VARCHAR(255) NOT NULL,"
        "app_id         VARCHAR(255) NOT NULL,"
        "type           VARCHAR(255) NOT NULL,"
        "platform       VARCHAR(255) NOT NULL,"
        "last_update    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,"
        "CONSTRAINT push_tokens_pkey PRIMARY KEY (id)
    )"
    -->
    <param name="odbc_dsn" value="pgsql://hostaddr=$${odbc_host} dbname=$${odbc_db} user=$${odbc_user} password=$${odbc_pass} options='-c client_min_messages=NOTICE'" />
    <!-- Name of REGISTER contact parameter, which should contain VOIP token
            value from contact parameter `contact_voip_token_param` will be stored to db with ${type}: `voip` and can be used as
            ${token} in url and/or post parameters
    -->
    <param name="contact_voip_token_param" value="pn-voip-tok"/>
    <!-- Name of REGISTER contact parameter, which should contain IM token
            value from contact parameter `contact_im_token_param` will be stored to db with ${type}: `im` and can be used as
            ${token} in url and/or post parameters
    -->
    <param name="contact_im_token_param" value="pn-im-tok"/>
    <!-- Name of REGISTER contact parameter, which should contain application id
            value from contact parameter `contact_app_id_param` will be stored to db and can be used as
            ${app_id} in url and/or post parameters
    -->
    <param name="contact_app_id_param" value="app-id"/>
    <!-- Name of REGISTER contact parameter, which should contain platform
            value from contact parameter `contact_app_id_param` will be stored to db and can be used as
            ${platform} in url and/or post parameters
    -->
    <param name="contact_platform_param" value="pn-platform"/>
</settings>
```

### Profiles configuration
```xml
<profile name="voip">
    <param name="id" value="0"/>
    <!-- URI template parameter with variables: ${type}, ${user}, ${realm}, ${token}, ${app_id}, ${platform}, ${x_call_id} -->
    <param name="url" value="http://somedomain.com/?type=${type}&app_id=${app_id}&user=${user}&realm=${realm}&token=${token}&platform=${platform}&payload=${payload}&aleg_uuid=${aleg_uuid}&cid_name=${cid_name}&cid_number=${cid_number}&x_call_id=${x_call_id}"/>
    <!-- Supported methods: GET and POST -->
    <param name="method" value="post"/>
    <!-- Optional parameter. Supported auth types: None, JWT, DIGEST, BASIC -->
    <!--<param name="auth_type" value="digest"/> -->
    <!-- Optional parameter. For JWT add token only, for digest or basic: login:password -->
    <!--<param name="auth_data" value="admin:password"/> -->
    <!-- Optional parameter. Will be added header Content-Type with value from this parameter -->
    <!--<param name="content_type" value=""/> -->
    <!-- Optional parameter. Libcurl connect_timeout parameter, sec -->
    <param name="connect_timeout" value="300"/>
    <!-- Optional parameter. CURL timeout parameter, sec -->
    <param name="timeout" value="0"/>
    <!-- Post body template use variables:
            ${type}, - voip or im
            ${app_id}, - application id from db (whatever you set to `contact_app_id_param`)
            ${user}, - user extension number
            ${realm}, - Realm
            ${token}, - token
            ${platform} - platform (whatever you set to `contact_platform_param`)
            ${payload} - json body of payload (cli command apn only)
            ${x_call_id} - SIP Call-ID for correlation
        Default value: {"type": "${type}",
                        "app":"${app_id}",
                        "token":"${token}",
                        "user":"${user}",
                        "realm":"${realm}",
                        "payload":${payload},
                        "platform":"${platform}"}
    -->
    <param name="post_data_template" value="type=${type}&app_id=${app_id}&user=${user}&realm=${realm}&token=${token}&platform=${platform}&payload=${payload}&x_call_id=${x_call_id}"/>
</profile>
```

Mod APN support two types of push notification: `voip` and `im`.<br>

#### Templates
You can configure `url` and `body` for your http request with template variables:
 - `${user}` - extension of device tokens owner
 - `${type}` - type of token (voip or im)
 - `${realm}` - realm name
 - `${x_call_id}` - SIP Call-ID for correlation
##### Stored in db (from `Contact` parameters of SIP REGISTER)
 - `${token}` - pn-token
 - `${app_id}` - application id
 - `${platform}` -  platform 

Change your dial-string user's parameter for use endpoint `app_wait`
```xml
<include>
  <user id="101">
    <params>
	  <!--...-->
	  <param name="dial-string" value="${sofia_contact(${dialed_user}@${dialed_domain})}:_:apn_wait/${dialed_user}@${dialed_domain}"/>
	  <!--...-->
    </params>
    <variables>
	<!--...-->
    </variables>
  </user>
</include>
```
## Auto load
```sh
$ sed -i '/<load module="mod_sofia"\/>/a <load module="mod_apn"\/>' /etc/freeswitch/autoload_configs/modules.conf.xml
```
## Manual load
```sh
$ fs_cli -rx 'load mod_apn'
```
## How it works
Any platform devices (iOS based, Android based, browser based) application sent SIP REGISTER request with custom contact parameters:
```
Contact: "101" <sip:101@192.168.31.100:56568;app-id=****;pn-voip-tok=XXXXXXXXX;pn-im-tok=XXXXXXXXXX;pn-platform=iOS>
```
- `app-id`
- `pn-voip-tok`
- `pn-im-tok` (Used for iOS instant messaging, no need of used for voip only)
- `pn-platform`

Mod APN store to db tokens when parse `Contact` header from REGISTER<br>
In case if Freeswitch will genarate to `User 101` a call, endpoint `apn_wait` will send http request to push notification service with token ID and wait for incoming REGISTER request from current user.<br>
After receiving SIP REGISTER, module will originate INVITE to `User 101`.

## Send notification
### From event
#### headers
`type`: 'voip' or 'im'<br>
`realm`: string value of realm name<br>
`user`: string value of user extension<br>
`x_call_id`: SIP Call-ID for correlation<br>
#### body (optional)
JSON object with payload data
`body` - string valueg<br>
`barge` - integer value<br>
`sound` - string value<br>
`content_available` - boolean value<br>
`action_key` - string value<br>
`image` - string value<br>
`category` - string value<br>
`title` - string value<br>
`localized_key` - string value<br>
`localized_args` - json array with string elements<br>
`title_localized_key` - string value<br>
`title_localized_args` - json array with string elements<br>
`custom` - array of json objects, with custom values<br>

#### Examples
##### SIP REGISTER message from iOS device
```
   REGISTER sip:local.carusto.com SIP/2.0
   Via: SIP/2.0/TCP 192.168.31.100:64503;rport;branch=z9hG4bKPjCopvkuNIv-OvRw5doGAOdEiyTYaSyd1W;alias
   Max-Forwards: 70
   From: <sip:101@local.carusto.com>;tag=nyxukpmU0h21yUHcowgbUJs3pqXrOzS6
   To: <sip:101@local.carusto.com>
   Call-ID: CDSaFEyhUvnJARMfMLS.UF6Jkv8PJ6lq
   CSeq: 48438 REGISTER
   Supported: outbound, path
   Contact: <sip:101@192.168.31.100:64503;transport=TCP;app-id=com.carusto.mobile.app;pn-voip-tok=39f161b205281f890715e625a7093d90af2fa281a7fcda82a7267f93d4b73df1;pn-platform=iOS;ob>;reg-id=1;+sip.instance="<urn:uuid:00000000-0000-0000-0000-0000d2b7e3b3>"
   Expires: 600
   Allow: PRACK, INVITE, ACK, BYE, CANCEL, UPDATE, INFO, SUBSCRIBE, NOTIFY, REFER, MESSAGE, OPTIONS
   Authorization: Digest username="101", realm="local.carusto.com", nonce="16472563-0102-11e7-b187-b112d280470a", uri="sip:local.carusto.com", response="6c53edfe29129b45a57664a3875de0c9", algorithm=MD5, cnonce="PqC351P2x33H2v4m95FoOAXQDxP9ap91", qop=auth, nc=00000001
   Content-Length:  0

```

##### Event for mod_apn
```
Event-Name: CUSTOM
Event-Subclass: mobile::push::notification
type: voip
realm: local.carusto.com
user: 100
app_id: com.carusto.mobile.app

{
  "barge":1,
  "body":"Body message",
  "sound":"default",
  "content_available":true,
  "image":"image.png",
  "category":"VOIP",
  "title":"Some title",
  "custom":[
    {
      "name":"Custom string variable",
      "value":"test string"
    },{
      "name":"Custom integer variable",
      "value":1000
    }
  ]
}
```
### From cli/api command to existing token(s)
```sh
$ fs_cli -x 'apn {"type":"voip","realm":"local.carusto.com","user":"100"}'
```
or
```sh
$ fs_cli -x 'apn {"type":"im","payload":{"body":"Text alert message","sound":"default"},"user":"100","realm":"local.carusto.com"}'
```

## Important
Mod APN will send http request for each token of stored user tokens. 
