const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

let browser, server, buffer = '', sequence = 0, checks = 0, posts = 0;
const pending = new Map();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'pulse-click-browser-'));
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
const timeout = setTimeout(() => { console.error('Browser verification timed out.'); process.exitCode = 1; browser?.kill(); server?.closeAllConnections(); server?.close(); }, 60000);
(async () => {
	const sdk = fs.readFileSync(path.join(__dirname, '../assets/tracker.js'));
	server = http.createServer((req, res) => {
		if (req.method === 'POST') posts++;
		res.setHeader('Content-Type', req.url === '/tracker.js' ? 'application/javascript' : 'text/html');
		if (req.url === '/tracker.js') { res.end(sdk); return; }
		res.end(`<!doctype html><meta charset="utf-8"><title>Click SDK fixture</title>
		<nav id="menu"><a id="link" class="other promo-product" href="#destination">Product <span id="icon">icon</span></a>
		<a id="excluded" class="promo-product no-track" href="#destination">Excluded</a></nav>
		<button id="button" class="promo-buy">Buy</button><button id="disabled" class="promo-buy" disabled>Disabled</button>
		<a id="outside" href="#destination">Outside rules</a><div id="dynamic"></div>
		<script src="/tracker.js"></script><script>
		window.events=[];window.errors=[];window.information=[];
		window.options={id:'fixture',pages:false,clicks:{include:[{pages:['/offers/'],selector:'#menu a[href]'},
		{selector:'a[href], button',classPrefix:'promo-'}],exclude:[{selector:'.no-track'}]},
		getContext:info=>{information.push({type:info.type,id:info.element.id});return {id:info.element.id};},
		emit:event=>events.push(event),onError:(error,info)=>errors.push({code:error.code,type:info.type})};
		window.tracker=DruidTracker.create(options).start();
		</script>`);
	});
	await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
	const base = `http://127.0.0.1:${server.address().port}`;
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
		const point = await evaluate(session, `(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
		await command('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button, clickCount: 1 }, session);
		await command('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button, clickCount: 1 }, session);
	}
	async function key(key, code, windowsVirtualKeyCode) {
		await command('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode, text: key === 'Enter' ? '\r' : key === ' ' ? ' ' : undefined }, session);
		await command('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode }, session);
	}
	const count = () => evaluate(session, 'events.length');
	await navigate(base + '/offers/?campaign=example');
	check(await count() === 0, 'pages:false captured a view.');
	await click('#icon');
	check(await count() === 1 && await evaluate(session, 'events[0].context.id === "link" && information[0].id === "link" && events[0].type === "click"'), 'Nested icon or overlapping rules duplicated/misidentified the link.');
	await click('#excluded'); await click('#outside'); await click('#disabled');
	check(await count() === 1, 'An excluded, unmatched or disabled element was captured.');
	await evaluate(session, 'document.querySelector("#link").focus()');
	await key('Enter', 'Enter', 13);
	check(await count() === 2, 'Keyboard link activation was not captured once.');
	await evaluate(session, 'document.querySelector("#button").focus()');
	await key(' ', 'Space', 32); await key('Enter', 'Enter', 13);
	check(await count() === 4, 'Native button Space/Enter activations were duplicated or missing: ' + JSON.stringify(await evaluate(session, 'events')));
	await key('Tab', 'Tab', 9);
	await evaluate(session, 'document.querySelector("#link").click();document.querySelector("#button").dispatchEvent(new MouseEvent("click",{bubbles:true}))');
	check(await count() === 4, 'Focus or programmatic clicks were captured.');
	await command('Emulation.setTouchEmulationEnabled', { enabled: true }, session);
	const point = await evaluate(session, '(()=>{const r=document.querySelector("#button").getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()');
	await command('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [point] }, session);
	await command('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }, session);
	await until(async () => await count() === 5, 'Touch did not produce exactly one click.'); checks++;
	await command('Emulation.setTouchEmulationEnabled', { enabled: false }, session);
	await click('#link', 'middle');
	check(await count() === 6, 'Middle-button link activation did not count once.');
	await click('#button', 'middle'); await click('#button', 'right');
	check(await count() === 6, 'Right click or middle click on a button was captured.');
	await evaluate(session, 'document.querySelector("#dynamic").innerHTML=`<button class="promo-new" id="added">Dynamic</button>`;document.querySelector("#added").addEventListener("click",event=>event.stopPropagation());');
	await click('#added');
	check(await count() === 7, 'Dynamic content or stopped bubbling prevented capture.');
	await evaluate(session, 'options.clicks.include.length=0; tracker.start(); DruidTracker.create({id:"fixture"}).start();');
	await click('#button');
	check(await count() === 8, 'Configuration mutation or repeated start changed capture.');
	await evaluate(session, 'tracker.stop()'); await click('#button');
	check(await count() === 8, 'Stopped tracker retained its click listener.');
	await evaluate(session, 'tracker.start()'); await click('#button');
	check(await count() === 9, 'Restart duplicated listeners.');
	await evaluate(session, 'tracker.track("custom",{explicit:true})');
	check(await count() === 10 && await evaluate(session, 'events.at(-1).type === "custom"'), 'Manual events were restricted by click rules.');
	for (const clicks of [true, null, [], { include: true }, { include: [{}] }, { include: [{ selector: '[' }] }, { include: [{ selector: 'a', pages: ['/x?y'] }] }, { include: [{ selector: 'a', classPrefix: 'two words' }] }]) {
		check(await evaluate(session, `(()=>{try{DruidTracker.create({...options,id:'invalid',clicks:${JSON.stringify(clicks)}});return false;}catch(e){return e.code==='invalid_configuration';}})()`), 'Invalid click configuration was accepted.');
	}
	await evaluate(session, 'tracker.stop();window.skipped=DruidTracker.create({...options,id:"skip",clicks:{include:[{selector:"button"}]},getContext:()=>null}).start()');
	await click('#button'); check(await count() === 10, 'Null context did not skip the interaction.');
	await evaluate(session, 'skipped.stop();DruidTracker.create({...options,id:"broken",clicks:{include:[{selector:"button"}]},getContext:()=>{throw new Error("private context")}}).start()');
	await click('#button');
	check(await evaluate(session, 'errors.length === 1 && errors[0].code === "context_failed" && errors[0].type === "click"'), 'Click errors lack safe event attribution.');
	await evaluate(session, `document.querySelector('#dynamic').innerHTML='<a id="route-only" href="#route">Route</a>';window.routeEvents=[];DruidTracker.create({...options,id:'routes',clicks:{include:[{selector:'#route-only',pages:['/offers/']}]},emit:event=>routeEvents.push(event)}).start();`);
	await click('#route-only');
	check(await evaluate(session, 'routeEvents.length === 1'), 'A page-specific click rule did not ignore query/fragment.');
	await evaluate(session, 'history.replaceState(null,"","/other/")'); await click('#route-only');
	check(await evaluate(session, 'routeEvents.length === 1'), 'A click rule matched a different source pathname.');
	await evaluate(session, 'history.replaceState(null,"","/offers")'); await click('#route-only');
	check(await evaluate(session, 'routeEvents.length === 1'), 'Click rules normalized trailing slashes.');
	check(posts === 0, 'The generic SDK sent an HTTP event.');
	if (process.env.WP_TEST_URL && process.env.WP_TEST_CREDENTIALS && process.env.WP_TEST_PAGE) {
		const wpBase = process.env.WP_TEST_URL;
		const credentials = JSON.parse(fs.readFileSync(process.env.WP_TEST_CREDENTIALS));
		await navigate(wpBase + '/wp-login.php');
		await evaluate(session, `document.querySelector('#user_login').value=${JSON.stringify(credentials.admin_user)};document.querySelector('#user_pass').value=${JSON.stringify(credentials.admin_pass)};document.querySelector('#loginform').requestSubmit();`);
		await until(() => evaluate(session, 'location.pathname.includes("/wp-admin/") && document.readyState === "complete"'), 'WordPress login failed.');
		await navigate(wpBase + '/wp-admin/admin.php?page=wp-druid-pulse-clicks');
		await evaluate(session, `document.querySelectorAll('[data-remove]').forEach(button=>button.click());document.querySelector('[data-add]').click();document.querySelector('[data-field="selector"]').value='[';document.querySelector('#wp-druid-pulse-click-rules').requestSubmit();`);
		check(await evaluate(session, 'document.querySelector("[data-field=selector]").validationMessage.length > 0'), 'The editor accepted invalid CSS.');
		await evaluate(session, `const field=document.querySelector('[data-field="selector"]');field.value='#phase8-link, #phase8-button, #phase8-phone, #phase8-mail';field.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('#wp-druid-pulse-click-rules').requestSubmit();`);
		await until(() => evaluate(session, '!!document.querySelector(".notice-success") && document.readyState === "complete"'), 'Click rules were not saved through the editor.').catch(async error => {
			throw new Error(error.message + ' ' + await evaluate(session, 'location.href + " " + document.body.innerText.slice(-1400)'));
		}); checks++;
		if (process.env.WP_TEST_SCREENSHOT) {
			await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }, session);
			const screenshot = await command('Page.captureScreenshot', { format: 'png' }, session);
			fs.writeFileSync(process.env.WP_TEST_SCREENSHOT, Buffer.from(screenshot.data, 'base64'));
		}
		await navigate(process.env.WP_TEST_PAGE);
		await until(() => evaluate(session, '!!window.wpDruidPulse?.tracker'), 'The WordPress click integration did not start.');
		await evaluate(session, `window.receipts=[];window.addEventListener('wp-druid-pulse:tracking-result',event=>receipts.push(event.detail));`);
		await click('#phase8-button');
		await until(() => evaluate(session, 'receipts.length === 1'), 'A browser button click did not reach WordPress.');
		check(await evaluate(session, 'receipts[0].type === "click" && receipts[0].result.status === "accepted"'), 'WordPress did not accept a browser button click.');
		await evaluate(session, `document.querySelector('#phase8-phone').addEventListener('click',event=>event.preventDefault());document.querySelector('#phase8-mail').addEventListener('click',event=>event.preventDefault());`);
		await click('#phase8-phone'); await click('#phase8-mail');
		await until(() => evaluate(session, 'receipts.length === 3'), 'Contact clicks did not complete.');
		check(await evaluate(session, 'receipts.every(receipt=>receipt.result.status === "accepted")'), 'WordPress rejected a telephone/email destination.');
		const receipts = await evaluate(session, 'receipts');
		if (process.env.WP_TEST_RECEIPTS) fs.writeFileSync(process.env.WP_TEST_RECEIPTS, JSON.stringify(receipts, null, 2));
		await click('#phase8-link span');
		await until(() => evaluate(session, 'location.pathname === "/destination" || location.pathname === "/destination/"'), 'Tracking blocked link navigation.'); checks++;
	}
	console.log(`PASS: ${checks} real-browser click assertions in ${version.product}; independent SDK makes no event HTTP requests.`);
})().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
	clearTimeout(timeout);
	if (browser) { try { await command('Browser.close'); } catch {} browser.kill(); }
	if (server) { server.closeAllConnections(); server.close(); }
	fs.rmSync(profile, { recursive: true, force: true });
});
