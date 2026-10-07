"""Exercise real local account requests and SMTP delivery without logging secrets."""
import http.cookiejar
import json
import re
import time
import urllib.error
import urllib.request
import uuid

base = 'http://127.0.0.1:8089'
api = base+'/api/v1'

def request(path, data=None, method='GET', token=None):
    headers = {'Accept':'application/json'}
    if token: headers['Authorization'] = 'Bearer '+token
    if isinstance(data, dict):
        data = json.dumps(data).encode(); headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(api+path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req) as response: return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)

def verified(email):
    status, result = request('/rider/registration/email/send', {'email':email}, 'POST')
    assert status == 200 and result['success'], 'Verification email was not delivered'
    with urllib.request.urlopen('http://127.0.0.1:8028/messages') as response: messages = json.load(response)
    message = next(item for item in messages if item['to'] == email)
    code = re.search(r'\b([0-9]{6})\b', message['subject']).group(1)
    status, result = request('/rider/registration/email/verify', {'email':email,'code':code}, 'POST')
    assert status == 200 and result['success'], 'Delivered email code was rejected'
    return result['token']

def multipart(fields):
    boundary = 'bagoo_'+uuid.uuid4().hex
    parts = []
    for name, value in fields.items():
        parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n').encode())
    for name in ['id_document','driver_license','or_cr_document']:
        parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="demo.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode()+b'%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    return b''.join(parts), 'multipart/form-data; boundary='+boundary

def application(email, token):
    return {'name':'Local Test Rider','email':email,'phone':'09173334444','birthday':'2000-01-01',
        'address':'Sample Street','province':'Metro Manila','city':'Pasig','barangay':'San Antonio',
        'vehicle_type':'Motorcycle','plate_number':'DEMO-123','license_number':'DEMO-123',
        'password':'Password1234','password_confirmation':'Password1234','otp_token':token,'device_name':'Local account check'}

def web_client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

status, session = request('/auth/tokens', {'email':'rider@bagoo.test','password':'Password1234','device_name':'Local check'}, 'POST')
assert status == 200 and session['data']['user']['can_access_portal']
token = session['data']['token']
assert request('/rider/me', token=token)[0] == 200
assert request('/auth/tokens/current', method='DELETE', token=token)[0] == 200
assert request('/rider/me', token=token)[0] == 401
print('Existing rider login, account read and token revocation verified.')

email = 'native.'+str(int(time.time()))+'@bagoo.test'
payload, kind = multipart(application(email, verified(email)))
req = urllib.request.Request(api+'/rider/applications', data=payload, headers={'Accept':'application/json','Content-Type':kind}, method='POST')
try:
    with urllib.request.urlopen(req) as response:
        assert response.status == 201
        session = json.load(response)['data']
except urllib.error.HTTPError as error:
    body = json.load(error)
    print('Registration validation:', body.get('errors', {}))
    raise SystemExit('Local registration check did not pass.')
assert session['user']['status'] == 'pending_approval' and session['user']['email_verified']
status, profile = request('/rider/me', token=session['token'])
assert status == 200 and profile['data']['access_state'] == 'holding'
assert 'id_document_path' not in profile['data']
client = web_client()
with client.open(base+'/courier/login') as response: page = response.read().decode()
csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', page).group(1)
data = urllib.parse.urlencode({'_token':csrf,'email':email,'password':'Password1234'}).encode()
with client.open(urllib.request.Request(base+'/login', data=data, method='POST')) as response:
    assert '/pending-approval' in response.url and response.status == 200
assert request('/auth/tokens/current', method='DELETE', token=session['token'])[0] == 200
print('Native registration, actual email delivery, private uploads and the same account signing into the web verified.')

email = 'web.'+str(int(time.time()))+'@bagoo.test'
fields = application(email, verified(email)); fields['role'] = 'courier'
client = web_client()
with client.open(base+'/courier/register') as response: page = response.read().decode()
fields['_token'] = re.search(r'<meta name="csrf-token" content="([^"]+)"', page).group(1)
payload, kind = multipart(fields)
with client.open(urllib.request.Request(base+'/register', data=payload, headers={'Content-Type':kind}, method='POST')) as response:
    assert '/pending-approval' in response.url and response.status == 200
status, result = request('/auth/tokens', {'email':email,'password':'Password1234','device_name':'Local check'}, 'POST')
assert status == 200 and result['data']['user']['access_state'] == 'holding'
assert request('/auth/tokens/current', method='DELETE', token=result['data']['token'])[0] == 200
print('Web registration followed by native API login/logout verified against the same database and shared registration service.')
