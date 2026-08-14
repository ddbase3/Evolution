<?php
$health = is_array($this->_['health'] ?? null) ? $this->_['health'] : [];
$checks = is_array($health['checks'] ?? null) ? $health['checks'] : [];
$analysisReady = ($health['analysis_ready'] ?? false) === true;
$applyReady = ($health['apply_ready'] ?? false) === true;
$actionUrl = (string)($this->_['action_url'] ?? 'index.php?name=evolutionaction&out=json');
$containerId = 'evolution_' . str_replace('.', '_', uniqid('', true));
$healthJson = json_encode($health, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$actionUrlJson = json_encode($actionUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div id="<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?>" class="evolution-app">
	<style>
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> {
			--ev-bg: #f5f6f8;
			--ev-panel: #ffffff;
			--ev-border: #d9dde5;
			--ev-text: #20242c;
			--ev-muted: #6a7280;
			--ev-ok: #207a4a;
			--ev-warn: #9a6410;
			--ev-error: #b13636;
			--ev-accent: #315ea8;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			color: var(--ev-text);
			background: var(--ev-bg);
			padding: 24px;
			min-height: 100vh;
			box-sizing: border-box;
		}
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> * { box-sizing: border-box; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-shell { max-width: 1180px; margin: 0 auto; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-header { display:flex; justify-content:space-between; gap:24px; align-items:flex-start; margin-bottom:20px; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> h1 { margin:0 0 5px; font-size:28px; font-weight:650; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> h2 { margin:0; font-size:18px; font-weight:650; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-subtitle { color:var(--ev-muted); max-width:760px; line-height:1.5; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-ready { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-pill { border:1px solid var(--ev-border); background:var(--ev-panel); padding:6px 10px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-pill.ok { border-color:#a8d7be; color:var(--ev-ok); background:#f0faf4; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-pill.error { border-color:#edb5b5; color:var(--ev-error); background:#fff4f4; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-panel { background:var(--ev-panel); border:1px solid var(--ev-border); border-radius:10px; margin-bottom:18px; overflow:hidden; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-panel-head { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 18px; border-bottom:1px solid var(--ev-border); }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-panel-body { padding:18px; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-actions { display:flex; gap:8px; flex-wrap:wrap; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> button { appearance:none; border:1px solid #aab2c0; background:#fff; color:#252a33; border-radius:6px; padding:8px 13px; font:inherit; font-weight:600; cursor:pointer; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> button:hover:not(:disabled) { background:#f4f6f9; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> button.primary { color:#fff; background:var(--ev-accent); border-color:var(--ev-accent); }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> button.danger { color:#fff; background:#8f3434; border-color:#8f3434; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> button:disabled { opacity:.45; cursor:not-allowed; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> textarea { width:100%; min-height:150px; resize:vertical; border:1px solid #b8bfca; border-radius:7px; padding:12px; font:inherit; line-height:1.5; background:#fff; color:inherit; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> textarea:focus { outline:2px solid rgba(49,94,168,.16); border-color:var(--ev-accent); }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-checks { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:10px; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-check { border:1px solid var(--ev-border); border-radius:8px; padding:12px 13px; background:#fbfcfd; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-check-top { display:flex; align-items:center; gap:8px; margin-bottom:5px; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-dot { width:9px; height:9px; border-radius:50%; background:#9299a5; flex:0 0 auto; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-check[data-status="ok"] .ev-dot { background:var(--ev-ok); }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-check[data-status="warning"] .ev-dot { background:var(--ev-warn); }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-check[data-status="error"] .ev-dot { background:var(--ev-error); }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-check-label { font-weight:650; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-check-message { color:var(--ev-muted); font-size:13px; line-height:1.4; white-space:pre-wrap; overflow-wrap:anywhere; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-status-text { font-size:13px; color:var(--ev-muted); }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-output { display:none; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-output.visible { display:block; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-plan, #<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-diff, #<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-log { white-space:pre-wrap; overflow-wrap:anywhere; margin:0; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:13px; line-height:1.55; background:#f8f9fb; border:1px solid var(--ev-border); border-radius:7px; padding:14px; max-height:600px; overflow:auto; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-message { display:none; padding:11px 13px; border-radius:7px; margin-bottom:14px; border:1px solid var(--ev-border); white-space:pre-wrap; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-message.visible { display:block; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-message.error { border-color:#edb5b5; background:#fff4f4; color:#7d2727; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-message.ok { border-color:#a8d7be; background:#f0faf4; color:#175f39; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-meta { display:flex; gap:14px; flex-wrap:wrap; color:var(--ev-muted); font-size:12px; margin-top:10px; }
		#<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-spinner { display:inline-block; width:13px; height:13px; border:2px solid currentColor; border-right-color:transparent; border-radius:50%; animation:evspin .8s linear infinite; vertical-align:-2px; margin-right:6px; }
		@keyframes evspin { to { transform:rotate(360deg); } }
		@media (max-width:760px) { #<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> { padding:14px; } #<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-header { flex-direction:column; } #<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?> .ev-ready { justify-content:flex-start; } }
	</style>

	<div class="ev-shell">
		<div class="ev-header">
			<div>
				<h1>BASE3 Evolution</h1>
				<div class="ev-subtitle">Analyze the current BASE3 application, produce an explicit change plan, then apply the approved plan through the MissionBay agent and controlled workspace tools.</div>
			</div>
			<div class="ev-ready">
				<span class="ev-pill <?php echo $analysisReady ? 'ok' : 'error'; ?>" data-role="analysis-ready">Analysis <?php echo $analysisReady ? 'ready' : 'blocked'; ?></span>
				<span class="ev-pill <?php echo $applyReady ? 'ok' : 'error'; ?>" data-role="apply-ready">Apply <?php echo $applyReady ? 'ready' : 'blocked'; ?></span>
			</div>
		</div>

		<div class="ev-panel">
			<div class="ev-panel-head">
				<div>
					<h2>System self-check</h2>
					<div class="ev-status-text">Configuration, services, storage, LLM, agent flow, workspace and Git safety.</div>
				</div>
				<div class="ev-actions">
					<button type="button" data-action="llm-test">Test LLM</button>
					<button type="button" data-action="health">Run checks</button>
				</div>
			</div>
			<div class="ev-panel-body">
				<div class="ev-message" data-role="health-message"></div>
				<div class="ev-checks" data-role="checks">
					<?php foreach ($checks as $check): ?>
						<?php $status = (string)($check['status'] ?? 'error'); ?>
						<div class="ev-check" data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
							<div class="ev-check-top"><span class="ev-dot"></span><span class="ev-check-label"><?php echo htmlspecialchars((string)($check['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
							<div class="ev-check-message"><?php echo htmlspecialchars((string)($check['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="ev-panel">
			<div class="ev-panel-head">
				<div>
					<h2>Requested change</h2>
					<div class="ev-status-text">Analysis is read-only. Source mutation is technically disabled until Apply.</div>
				</div>
			</div>
			<div class="ev-panel-body">
				<div class="ev-message" data-role="request-message"></div>
				<textarea data-role="prompt" placeholder="Describe what should be added, changed, refactored or removed. The agent will inspect the actual BASE3 source before proposing a plan."></textarea>
				<div class="ev-actions" style="margin-top:10px;">
					<button type="button" class="primary" data-action="analyze" <?php echo $analysisReady ? '' : 'disabled'; ?>>Analyze change</button>
				</div>
			</div>
		</div>

		<div class="ev-panel ev-output" data-role="plan-panel">
			<div class="ev-panel-head">
				<div>
					<h2>Proposed change</h2>
					<div class="ev-status-text">Review the plan before allowing any source mutation.</div>
				</div>
				<div class="ev-actions">
					<button type="button" class="danger" data-action="apply" disabled>Apply approved plan</button>
				</div>
			</div>
			<div class="ev-panel-body">
				<div class="ev-message" data-role="apply-message"></div>
				<pre class="ev-plan" data-role="plan"></pre>
				<div class="ev-meta"><span data-role="change-id"></span><span data-role="base-head"></span></div>
			</div>
		</div>

		<div class="ev-panel ev-output" data-role="result-panel">
			<div class="ev-panel-head"><h2>Applied change</h2></div>
			<div class="ev-panel-body">
				<div class="ev-message" data-role="result-message"></div>
				<div class="ev-status-text" style="margin-bottom:7px;">Changed source</div>
				<pre class="ev-diff" data-role="diff"></pre>
			</div>
		</div>
	</div>

	<script>
	(function() {
		var root = document.getElementById(<?php echo json_encode($containerId); ?>);
		if (!root) return;

		var actionUrl = <?php echo $actionUrlJson ?: '"index.php?name=evolutionaction&out=json"'; ?>;
		var health = <?php echo $healthJson ?: '{}'; ?>;
		var currentChangeId = '';

		function el(role) { return root.querySelector('[data-role="' + role + '"]'); }
		function button(action) { return root.querySelector('[data-action="' + action + '"]'); }
		function setBusy(btn, busy, label) {
			if (!btn) return;
			if (busy) {
				btn.dataset.label = btn.textContent;
				btn.innerHTML = '<span class="ev-spinner"></span>' + label;
				btn.disabled = true;
			} else {
				btn.textContent = btn.dataset.label || label;
				btn.disabled = false;
			}
		}
		function message(role, text, type) {
			var target = el(role);
			if (!target) return;
			target.textContent = text || '';
			target.className = 'ev-message' + (text ? ' visible ' + (type || '') : '');
		}
		function escapeHtml(value) {
			return String(value).replace(/[&<>"']/g, function(c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c]; });
		}
		function renderHealth(data) {
			health = data || {};
			var checks = health.checks || {};
			var markup = '';
			Object.keys(checks).forEach(function(key) {
				var check = checks[key] || {};
				markup += '<div class="ev-check" data-status="' + escapeHtml(check.status || 'error') + '">' +
					'<div class="ev-check-top"><span class="ev-dot"></span><span class="ev-check-label">' + escapeHtml(check.label || key) + '</span></div>' +
					'<div class="ev-check-message">' + escapeHtml(check.message || '') + '</div></div>';
			});
			el('checks').innerHTML = markup;
			var analysis = el('analysis-ready');
			analysis.className = 'ev-pill ' + (health.analysis_ready ? 'ok' : 'error');
			analysis.textContent = 'Analysis ' + (health.analysis_ready ? 'ready' : 'blocked');
			var apply = el('apply-ready');
			apply.className = 'ev-pill ' + (health.apply_ready ? 'ok' : 'error');
			apply.textContent = 'Apply ' + (health.apply_ready ? 'ready' : 'blocked');
			button('analyze').disabled = !health.analysis_ready;
			button('apply').disabled = !(health.apply_ready && currentChangeId);
		}
		async function call(action, data) {
			var response = await fetch(actionUrl, {
				method: 'POST',
				headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
				body: JSON.stringify(Object.assign({action: action}, data || {})),
				credentials: 'same-origin'
			});
			var text = await response.text();
			try { return JSON.parse(text); }
			catch (e) { throw new Error('Evolution endpoint returned invalid JSON: ' + text.slice(0, 1000)); }
		}

		button('health').addEventListener('click', async function() {
			var btn = this;
			message('health-message', '', '');
			setBusy(btn, true, 'Checking...');
			try {
				var result = await call('health');
				renderHealth(result);
				message('health-message', result.analysis_ready ? 'Required analysis checks passed.' : 'One or more required checks failed. Resolve the errors shown below.', result.analysis_ready ? 'ok' : 'error');
			} catch (e) { message('health-message', e.message, 'error'); }
			finally { setBusy(btn, false, 'Run checks'); }
		});

		button('llm-test').addEventListener('click', async function() {
			var btn = this;
			message('health-message', '', '');
			setBusy(btn, true, 'Testing...');
			try {
				var result = await call('llm_test');
				message('health-message', result.message || (result.ok ? 'LLM test succeeded.' : 'LLM test failed.'), result.ok ? 'ok' : 'error');
			} catch (e) { message('health-message', e.message, 'error'); }
			finally { setBusy(btn, false, 'Test LLM'); }
		});

		button('analyze').addEventListener('click', async function() {
			var prompt = el('prompt').value.trim();
			if (!prompt) { message('request-message', 'Describe the requested change first.', 'error'); return; }
			var btn = this;
			message('request-message', '', '');
			message('apply-message', '', '');
			setBusy(btn, true, 'Analyzing...');
			try {
				var result = await call('analyze', {prompt: prompt});
				if (!result.ok) throw new Error(result.message || 'Evolution analysis failed.');
				currentChangeId = result.change_id || '';
				el('plan').textContent = result.plan || '';
				el('change-id').textContent = currentChangeId ? 'change: ' + currentChangeId : '';
				el('base-head').textContent = result.base_head ? 'base: ' + result.base_head : '';
				el('plan-panel').classList.add('visible');
				button('apply').disabled = !(health.apply_ready && currentChangeId);
				message('request-message', 'Read-only analysis completed. Review the proposed change before Apply.', 'ok');
			} catch (e) { message('request-message', e.message, 'error'); }
			finally { setBusy(btn, false, 'Analyze change'); button('analyze').disabled = !health.analysis_ready; }
		});

		button('apply').addEventListener('click', async function() {
			if (!currentChangeId) return;
			var btn = this;
			var completed = false;
			message('apply-message', '', '');
			setBusy(btn, true, 'Applying...');
			try {
				var result = await call('apply', {change_id: currentChangeId});
				if (!result.ok) {
					message('apply-message', result.message || 'Evolution apply failed.', 'error');
					if (result.validation) el('diff').textContent = JSON.stringify(result.validation, null, 2);
					el('result-panel').classList.add('visible');
					return;
				}
				el('diff').textContent = result.diff || (result.changed_paths || []).join('\n');
				el('result-panel').classList.add('visible');
				message('result-message', result.message || 'Evolution change applied.', 'ok');
				message('apply-message', 'Apply completed. Review and commit the resulting Git changes before starting another Apply.', 'ok');
				completed = true;
			} catch (e) { message('apply-message', e.message, 'error'); }
			finally {
				btn.textContent = btn.dataset.label || 'Apply approved plan';
				btn.disabled = completed || !(health.apply_ready && currentChangeId);
			}
		});

		renderHealth(health);
	})();
	</script>
</div>
