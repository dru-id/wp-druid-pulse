const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

let browser, buffer = '', sequence = 0, checks = 0;
const pending = new Map();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'pulse-admin-ux-'));
const check = (value, message) => { assert.ok(value, message); checks++; };
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function command(method, params = {}, sessionId) {
	return new Promise((resolve, reject) => {
		const id = ++sequence;
		pending.set(id, { resolve, reject });
		browser.stdio[3].write(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }) + '\0');
	});
}
async function evaluate(session, expression) {
	const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, session);
	if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
	return result.result.value;
}
async function until(probe, message) {
	for (let i = 0; i < 100; i++) { if (await probe()) return; await delay(30); }
	throw new Error(message);
}

if (!process.env.WP_TEST_URL || !process.env.WP_TEST_CREDENTIALS) throw new Error('An isolated WordPress URL and synthetic credentials are required.');
const timeout=setTimeout(()=>{console.error('Admin UX browser checks timed out.');process.exitCode=1;browser?.kill();},90000);
(async()=>{
	browser = spawn(process.env.CHROME || '/usr/bin/google-chrome', ['--headless=new', '--remote-debugging-pipe', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-extensions', '--disable-default-apps', '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', 'about:blank'], { stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'] });
	browser.stdio[4].on('data', chunk => {
		buffer += chunk;
		let index;
		while ((index = buffer.indexOf('\0')) >= 0) {
			const result = JSON.parse(buffer.slice(0, index)); buffer = buffer.slice(index + 1);
			const callback = pending.get(result.id);
			if (callback) { pending.delete(result.id); result.error ? callback.reject(new Error(result.error.message)) : callback.resolve(result.result); }
		}
	});
	const version = await command('Browser.getVersion');
	const target = await command('Target.createTarget', { url: 'about:blank' });
	const session = (await command('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;
	await command('Page.enable', {}, session);
	async function navigate(url) {
		await command('Page.navigate', { url }, session);
		await until(() => evaluate(session, `location.href === ${JSON.stringify(url)} && document.readyState === 'complete'`), 'Page did not load.');
	}
	async function click(selector, button = 'left') {
		const point = await evaluate(session, `(()=>{const element=document.querySelector(${JSON.stringify(selector)});element.scrollIntoView({block:'center'});const r=element.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
		await command('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button, clickCount: 1 }, session);
		await command('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button, clickCount: 1 }, session);
	}
	const base=process.env.WP_TEST_URL;
	const credentials=JSON.parse(fs.readFileSync(process.env.WP_TEST_CREDENTIALS));
	const form='#wp-druid-pulse-click-rules';
	const row=form+' tbody tr:first-child';
	const dialog='#pulse-page-picker';
	const visible=()=>evaluate(session,`document.querySelectorAll('${form} tbody tr:not([hidden])').length`);
	const change=async(selector,value)=>evaluate(session,`(()=>{const field=document.querySelector(${JSON.stringify(selector)});field.value=${JSON.stringify(value)};field.dispatchEvent(new Event('change',{bubbles:true}));field.dispatchEvent(new Event('input',{bubbles:true}));})()`);
	const waitResults=async count=>until(()=>evaluate(session,`document.querySelectorAll('${dialog} [data-page-results] input').length===${count} && !document.querySelector('${dialog} [data-search-status]').textContent.includes('Loading')`),'Page search did not complete.');
	const search=async(term,count)=>{await change(dialog+' input[type=search]',term);await click(dialog+' [data-search]');await waitResults(count);};
	const shot=async name=>{if(process.env.WP_TEST_SCREENSHOT_DIR){const result=await command('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(process.env.WP_TEST_SCREENSHOT_DIR,name+'.png'),Buffer.from(result.data,'base64'));}};
	await command('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);
	await navigate(base+'/wp-login.php');
	await evaluate(session,`document.querySelector('#user_login').value=${JSON.stringify(credentials.admin_user)};document.querySelector('#user_pass').value=${JSON.stringify(credentials.admin_pass)};document.querySelector('#loginform').requestSubmit();`);
	await until(()=>evaluate(session,'location.pathname.includes("/wp-admin/") && document.readyState==="complete"'),'Login failed.');
	await navigate(base+'/wp-admin/admin.php?page=wp-druid-pulse-clicks');
	check(await visible()===20,'Editor is not paginated at 20 rules.');
	check(await evaluate(session,`document.querySelector('${row} [data-selected-pages]').textContent.includes('UX browser page 01')`),'Saved page has no readable title.');
	const original=await evaluate(session,`document.querySelector('${row} [data-field=pages]').value`);
	await click(row+' [data-choose-pages]');await search('UX browser page',20);
	await click(dialog+' [data-page-results] li:nth-child(2) input');
	await click(dialog+' [data-search-next]');await waitResults(5);
	await click(dialog+' [data-page-results] li:last-child input');
	check(await evaluate(session,`document.querySelectorAll('${dialog} [data-chosen-pages] li').length===3`),'Picker lost selections between result pages.');
	await shot('page-picker');
	await click(dialog+' [data-cancel-pages]');
	check(await evaluate(session,`document.querySelector('${row} [data-field=pages]').value===${JSON.stringify(original)}`),'Cancel changed the page selection.');
	await click(row+' [data-choose-pages]');
	await click(dialog+' [data-chosen-pages] button');
	await search('UX browser page 25',1);
	const destination=await evaluate(session,`document.querySelector('${dialog} [data-page-results] small').textContent.split(' · ')[1]`);
	await click(dialog+' [data-page-results] input');await click(dialog+' [data-apply-pages]');
	check(await evaluate(session,`document.querySelector('${row} [data-selected-pages]').textContent==='UX browser page 25'`),'Page chooser did not apply its selection.');
	await change(row+' [data-preset]','a[href], button');await change(row+' [data-field=prefix]','');
	await change(row+' [data-preset]','custom');await change(row+' [data-field=selector]','[');
	await click(form+' [data-next]');check(await visible()===2,'Second rule page is not bounded.');
	await click(form+' [type=submit]');
	check(await evaluate(session,`!document.querySelector('${row}').hidden && document.querySelector('${row} [data-field=selector]').validationMessage.length>0`),'Invalid rule on another page was not revealed.');
	await change(row+' [data-preset]','a[href], button');
	await click(form+' [data-next]');
	await click(form+' tbody tr:nth-child(21) [data-remove]');
	await click(form+' [data-add]');
	check(await visible()===2,'Adding a rule did not navigate to the last page.');
	await click(form+' [type=submit]');
	check(await evaluate(session,`!document.querySelector('${form} [data-editor-error]').hidden && document.querySelector('${form} [data-editor-error]').textContent.includes('Choose at least one page')`),'New rule silently enabled tracking on every page.');
	const last=form+' tbody tr:last-child';
	await click(last+' [data-choose-pages]');await search('UX browser page 02',1);await click(dialog+' [data-page-results] input');await click(dialog+' [data-apply-pages]');
	await click(form+' [type=submit]');
	try {
		await until(()=>evaluate(session,'document.readyState==="complete" && document.querySelector(".notice-success")?.textContent.includes("Click rules saved.")'),'Click rules were not saved.');
	} catch(error) {
		await shot('save-failure');
		throw new Error(error.message+' '+await evaluate(session,'JSON.stringify({path:location.pathname, message:document.querySelector("[data-editor-error]")?.textContent || document.querySelector(".wp-die-message")?.textContent})'));
	}
	check(await evaluate(session,`document.querySelector('${row} [data-preset]').value==='a[href], button' && document.querySelector('${row} [data-field=prefix]').value==='' && document.querySelector('${row} [data-selected-pages]').textContent==='UX browser page 25'`),'First-page edits were lost when saving the last page.');
	check(await evaluate(session,`document.querySelectorAll('${form} tbody tr').length===22 && !Array.from(document.querySelectorAll('${form} [data-field=selector]')).some(input=>input.value==='#ux-20')`),'Deletion or total rule count is incorrect.');
	await shot('clicks');
	await command('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);
	check(await evaluate(session,'document.documentElement.scrollWidth<=window.innerWidth'),'Click editor overflows the mobile page.');
	await command('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);
	for(const slug of ['wp-druid-pulse','wp-druid-pulse-connection','wp-druid-pulse-diagnostics']){
		await navigate(base+'/wp-admin/admin.php?page='+slug);await shot(slug);
		check(await evaluate(session,'!!document.querySelector(".wrap h1")'),'An administration screen did not render.');
		if(slug==='wp-druid-pulse')check(await evaluate(session,'document.querySelector(".wrap h1").textContent.includes("Page views") && document.querySelectorAll(".widefat tbody tr").length<=20'),'Views are not clearly named or paginated.');
		if(slug.endsWith('connection'))check(await evaluate(session,'!document.querySelector(".wrap").textContent.includes("Installation ID:")'),'Connection still prominently shows the installation ID.');
		if(slug.endsWith('diagnostics'))check(await evaluate(session,'Array.from(document.querySelectorAll("details:not([open])")).some(details=>details.textContent.includes("Installation ID:"))'),'Technical identity is not collapsed in diagnostics.');
	}
	await command('Page.addScriptToEvaluateOnNewDocument',{source:'window.uxReceipts=[];window.addEventListener("wp-druid-pulse:tracking-result",e=>uxReceipts.push(e.detail));'},session);
	await navigate(destination);
	await click('#ux-test-button');
	await until(()=>evaluate(session,'window.uxReceipts.length===1'),'Saved rule did not capture a real frontend click.');
	check(await evaluate(session,'uxReceipts[0].type==="click" && uxReceipts[0].result.status==="accepted"'),'New all-links/buttons rule did not reach WordPress.');
	console.log(`PASS: ${checks} admin UX browser assertions in ${version.product}.`);
})().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
	clearTimeout(timeout);
	if(browser){const closed=new Promise(resolve=>browser.once('exit',resolve));browser.kill();await closed;}
	fs.rmSync(profile,{recursive:true,force:true});
});
