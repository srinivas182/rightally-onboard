"""Accessibility audit (axe-core, WCAG 2.1 A/AA + best practice) of client and admin pages.

Local or staging only. Run the app (php artisan serve), load demo data (DemoSeeder), then:
    AUDIT_EMAIL=admin@example.com AUDIT_PASSWORD=... AUDIT_TOTP_SECRET=... python3 tools/a11y/audit.py [signed-account-url]
Needs: pip install playwright && playwright install chromium; npm install (for axe-core).
Prints any violations; full results in storage/logs/axe.json.
"""
import asyncio,re,json,hmac,hashlib,struct,time,base64,sys,os
from playwright.async_api import async_playwright
AXE=open('node_modules/axe-core/axe.min.js').read()
B=os.environ.get('AUDIT_BASE_URL','http://127.0.0.1:8000')
ACCT=sys.argv[1] if len(sys.argv) > 1 else B + '/account'
def totp(s):
    k=base64.b32decode(s); c=struct.pack('>Q',int(time.time())//30); h=hmac.new(k,c,hashlib.sha1).digest(); o=h[-1]&15
    return str((struct.unpack('>I',h[o:o+4])[0]&0x7fffffff)%1000000).zfill(6)
results={}
async def audit(pg,name):
    await pg.add_script_tag(content=AXE)
    results[name]=await pg.evaluate("""async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','best-practice']}});return r.violations.map(v=>({id:v.id,impact:v.impact,help:v.help,nodes:v.nodes.slice(0,4).map(n=>n.target.join(' ')+' :: '+(n.failureSummary||'').slice(0,200))}))}""")
async def main():
  async with async_playwright() as p:
    b=await p.chromium.launch(); ctx=await b.new_context(viewport={'width':1280,'height':900}, bypass_csp=True); pg=await ctx.new_page()
    await pg.route(re.compile(r'https://fonts\.(googleapis|gstatic)\.com/.*'), lambda r: r.abort())
    await pg.goto(B+'/'); await audit(pg,'details')
    await pg.click('#detailsForm button[type=submit]'); await pg.wait_for_load_state(); await audit(pg,'details-errors')
    for k,v in {'first_name':'Ana','last_name':'Ruiz','title':'Owner','company_name':'Test Realty','email':'ana@test.com','phone':'3055550148','street':'1 Main','city':'Miami','zip':'33131'}.items():
        await pg.fill('#'+k,v)
    await pg.select_option('#state_code','FL'); await pg.click('#detailsForm button[type=submit]'); await pg.wait_for_load_state()
    await audit(pg,'agreement')
    await pg.goto(ACCT); await audit(pg,'account')
    await pg.goto(B+'/account'); await audit(pg,'account-request')
    await pg.goto(B+'/privacy'); await audit(pg,'privacy')
    await pg.goto(B+'/nope'); await audit(pg,'404')
    await pg.goto(B+'/admin/login'); await audit(pg,'admin-login')
    await pg.fill('input[name=email]',os.environ['AUDIT_EMAIL']); await pg.fill('input[name=password]',os.environ['AUDIT_PASSWORD']); await pg.click('button[type=submit]')
    await pg.wait_for_load_state(); await audit(pg,'admin-2fa'); await pg.fill('input[name=code]',totp(os.environ['AUDIT_TOTP_SECRET'])); await pg.click('button[type=submit]'); await pg.wait_for_load_state()
    await pg.wait_for_timeout(800); await audit(pg,'admin-dashboard')
    await pg.goto(B+'/admin/customers'); await audit(pg,'admin-customers')
    await pg.click('text=Keystone Homes'); await pg.wait_for_load_state(); await audit(pg,'admin-customer')
    await pg.goto(B+'/admin/invoices'); await audit(pg,'admin-invoices')
    await pg.goto(B+'/admin/settings'); await audit(pg,'admin-settings')
    await b.close()
asyncio.run(main())
json.dump(results,open('storage/logs/axe.json','w'),indent=1)
summary={}
for page,vs in results.items():
    for v in vs: summary.setdefault((v['id'],v['impact'],v['help']),[]).append(page)
for (i,imp,h),pages in sorted(summary.items(), key=lambda x:str(x[0][1])):
    print(f"[{imp}] {i}: {h}  ({', '.join(pages)})")
print('pages',len(results))
