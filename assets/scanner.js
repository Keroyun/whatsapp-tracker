	(function(){
		'use strict';
		var configNode=document.getElementById('awm-scanner-config'),cfg={};try{cfg=JSON.parse(configNode?configNode.getAttribute('data-config')||'{}':'{}');}catch(e){cfg={};}
		var ajaxUrl=cfg.ajaxUrl||'',nonce=cfg.nonce||'',shortlinkNonce=cfg.shortlinkNonce||'';
		var statusEl = document.getElementById('awm-scan-status');
		var progress = document.getElementById('awm-progress');
		var bar = document.getElementById('awm-progress-meter');
		var quickBtn = document.getElementById('awm-quick-scan');
		var deepBtn = document.getElementById('awm-deep-scan');
		var recentBtn = document.getElementById('awm-recent-scan');
		var currentBtn = document.getElementById('awm-current-scan');
		var currentUrl = document.getElementById('awm-current-url');
		var cancelBtn = document.getElementById('awm-cancel-scan');
		var resolveBtn = document.getElementById('awm-resolve-shortlinks');
		var shortStatus = document.getElementById('awm-shortlink-status');
		var shortProgress = document.getElementById('awm-shortlink-progress');
		var shortBar = document.getElementById('awm-shortlink-progress-meter');
		var manualShortlink = document.getElementById('awm-manual-shortlink');
		var manualNumber = document.getElementById('awm-manual-number');
		var manualSource = document.getElementById('awm-manual-source');
		var manualSaveBtn = document.getElementById('awm-save-shortlink-map');
		var currentScan = null;
		var stopped = false;
		var requestController = null;
		var scanDelay = Number(cfg.scanDelay||650);

		function post(data, timeoutMs){
			var body = new URLSearchParams(data);
			requestController = window.AbortController ? new AbortController() : null;
			var timer = requestController ? setTimeout(function(){requestController.abort();}, timeoutMs || 20000) : null;
			return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),signal:requestController?requestController.signal:undefined}).then(function(r){return r.json();}).finally(function(){if(timer)clearTimeout(timer);requestController=null;});
		}
		function setBusy(busy){
			[quickBtn,deepBtn,recentBtn,currentBtn,resolveBtn,manualSaveBtn].forEach(function(btn){if(btn)btn.disabled=busy;});
			if(currentUrl)currentUrl.disabled=busy;
			if(manualShortlink)manualShortlink.disabled=busy;
			if(manualNumber)manualNumber.disabled=busy;
			if(manualSource)manualSource.disabled=busy;
			cancelBtn.hidden=!(busy&&currentScan);
			progress.hidden=!(busy&&currentScan);
		}
		function setProgress(done,total,label){var pct=total?Math.min(100,Math.round((done/total)*100)):100;if(bar)bar.value=pct;statusEl.textContent=(label||'Scanning')+' '+done+' / '+total+' pages ('+pct+'%)';}
		function fail(message){
			var s=currentScan;currentScan=null;setBusy(false);statusEl.textContent=message||'Scan failed.';
			if(s&&s.scan_id){post({action:'awm_scan_cancel',nonce:nonce,scan_id:s.scan_id},10000).catch(function(){});}
		}
		function runFull(mode){
			if(mode==='deep'&&!window.confirm('Full Deep Scan fetches one rendered public page at a time and deliberately pauses between requests. Use this only when a full rendered-site verification is needed. Continue?')) return;
			stopped=false;setBusy(true);if(bar)bar.value=0;statusEl.textContent='Starting full scan…';
			post({action:'awm_scan_start',nonce:nonce,mode:mode}).then(function(res){
				if(!res.success) throw new Error((res.data&&res.data.message)||'Unable to start scan.');
				var s=res.data;currentScan=s;setBusy(true);setProgress(0,s.total,'Scanning');
				function batch(offset){
					if(stopped)return;
					post({action:'awm_scan_batch',nonce:nonce,scan_id:s.scan_id,mode:s.mode,offset:String(offset)},30000).then(function(r){
						if(stopped)return;
						if(!r.success) throw new Error((r.data&&r.data.message)||'Scan batch failed.');
						setProgress(r.data.next_offset,r.data.total,'Scanning');
						if(r.data.done){
							return post({action:'awm_scan_finalize',nonce:nonce,scan_id:s.scan_id}).then(function(fin){
								if(!fin.success) throw new Error((fin.data&&fin.data.message)||'Unable to finalize scan.');
								currentScan=null;statusEl.textContent='Full scan complete. Reloading inventory…';window.location.reload();
							});
						}
						setTimeout(function(){batch(r.data.next_offset);},scanDelay);
					}).catch(function(e){if(!stopped)fail(e.name==='AbortError'?'Scan request timed out or was cancelled.':e.message);});
				}
				batch(0);
			}).catch(function(e){if(!stopped)fail(e.name==='AbortError'?'Unable to start scan.':e.message);});
		}
		function runRecent(){
			stopped=false;setBusy(true);if(bar)bar.value=0;statusEl.textContent='Checking for recently changed pages…';
			post({action:'awm_recent_scan_start',nonce:nonce}).then(function(res){
				if(!res.success) throw new Error((res.data&&res.data.message)||'Unable to start Recent Changes scan.');
				var s=res.data;currentScan=s;setBusy(true);setProgress(0,s.total,'Updating');
				function batch(offset){
					if(stopped)return;
					post({action:'awm_recent_scan_batch',nonce:nonce,scan_id:s.scan_id,offset:String(offset)},30000).then(function(r){
						if(!r.success) throw new Error((r.data&&r.data.message)||'Recent Changes batch failed.');
						setProgress(r.data.next_offset,r.data.total,'Updating');
						if(r.data.done){
							return post({action:'awm_recent_scan_finalize',nonce:nonce,scan_id:s.scan_id}).then(function(fin){
								if(!fin.success) throw new Error((fin.data&&fin.data.message)||'Unable to finalize Recent Changes scan.');
								currentScan=null;statusEl.textContent=(s.total?'Recent changes updated.':'No changed pages found.')+' Reloading inventory…';window.location.reload();
							});
						}
						setTimeout(function(){batch(r.data.next_offset);},scanDelay);
					}).catch(function(e){if(!stopped)fail(e.name==='AbortError'?'Recent Changes request timed out or was cancelled.':e.message);});
				}
				batch(0);
			}).catch(function(e){if(!stopped)fail(e.name==='AbortError'?'Unable to start Recent Changes scan.':e.message);});
		}
		function runCurrent(){
			var url=(currentUrl.value||'').trim();
			if(!url){statusEl.textContent='Paste the page URL you want to scan.';currentUrl.focus();return;}
			setBusy(true);statusEl.textContent='Scanning one rendered page…';
			post({action:'awm_scan_current_page',nonce:nonce,page_url:url},20000).then(function(res){
				if(!res.success) throw new Error((res.data&&res.data.message)||'Unable to scan this page.');
				statusEl.textContent='Page updated ('+res.data.found+' WhatsApp link(s) found). Reloading inventory…';window.location.reload();
			}).catch(function(e){setBusy(false);statusEl.textContent=e.name==='AbortError'?'Current Page scan timed out.':e.message;});
		}
		function shortlinkPost(data, timeoutMs){
			data.nonce=shortlinkNonce;
			return post(data,timeoutMs||15000);
		}
		function runResolveShortlinks(){
			if(!resolveBtn)return;
			setBusy(true);
			if(shortProgress)shortProgress.hidden=false;
			if(shortBar)shortBar.value=0;
			if(shortStatus)shortStatus.textContent='Preparing unresolved shortlinks…';
			shortlinkPost({action:'awm_shortlinks_start'},10000).then(function(res){
				if(!res.success)throw new Error((res.data&&res.data.message)||'Unable to prepare shortlink resolution.');
				var items=(res.data&&Array.isArray(res.data.items))?res.data.items:[];
				var total=items.length,resolved=0,failed=0;
				if(!total){setBusy(false);if(shortProgress)shortProgress.hidden=true;if(shortStatus)shortStatus.textContent='No unresolved shortlinks found.';return;}
				function next(index){
					if(index>=total){
						return shortlinkPost({action:'awm_shortlinks_finalize'},10000).then(function(){
							if(shortBar)shortBar.value=100;
							if(shortStatus)shortStatus.textContent='Resolution complete: '+resolved+' resolved, '+failed+' still unresolved. Reloading…';
							window.location.reload();
						});
					}
					var destination=items[index];
					var pct=Math.round((index/total)*100);
					if(shortBar)shortBar.value=pct;
					if(shortStatus)shortStatus.textContent='Resolving '+(index+1)+' / '+total+': '+destination;
					shortlinkPost({action:'awm_resolve_shortlink',destination:destination},15000).then(function(r){
						if(!r.success){failed++;}else if(r.data&&r.data.resolved){resolved++;}else{failed++;}
						setTimeout(function(){next(index+1);},scanDelay);
					}).catch(function(){failed++;setTimeout(function(){next(index+1);},scanDelay);});
				}
				next(0);
			}).catch(function(e){setBusy(false);if(shortProgress)shortProgress.hidden=true;if(shortStatus)shortStatus.textContent=e.name==='AbortError'?'Shortlink resolution request timed out.':e.message;});
		}
		function saveManualShortlink(){
			if(!manualShortlink||!manualNumber)return;
			var destination=(manualShortlink.value||'').trim();
			var number=(manualNumber.value||'').replace(/\D/g,'');
			var source=manualSource?(manualSource.value||'').trim():'';
			if(!destination){if(shortStatus)shortStatus.textContent='Select a shortlink to map.';manualShortlink.focus();return;}
			if(!number){if(shortStatus)shortStatus.textContent='Enter the actual WhatsApp number in international format.';manualNumber.focus();return;}
			setBusy(true);if(shortStatus)shortStatus.textContent='Saving manual mapping…';
			shortlinkPost({action:'awm_map_shortlink_manual',destination:destination,number:number,source:source},10000).then(function(res){
				if(!res.success)throw new Error((res.data&&res.data.message)||'Unable to save mapping.');
				if(shortStatus)shortStatus.textContent=destination+' mapped to '+res.data.number+'. Reloading…';window.location.reload();
			}).catch(function(e){setBusy(false);if(shortStatus)shortStatus.textContent=e.name==='AbortError'?'Manual mapping request timed out.':e.message;});
		}
		if(manualSource)manualSource.addEventListener('change',function(){
			var opt=this.options[this.selectedIndex];
			var configured=opt?opt.getAttribute('data-number'):'';
			if(configured&&manualNumber)manualNumber.value=configured;
		});
		quickBtn.addEventListener('click',function(){runFull('quick');});
		deepBtn.addEventListener('click',function(){runFull('deep');});
		if(recentBtn)recentBtn.addEventListener('click',runRecent);
		if(currentBtn)currentBtn.addEventListener('click',runCurrent);
		if(resolveBtn)resolveBtn.addEventListener('click',runResolveShortlinks);
		if(manualSaveBtn)manualSaveBtn.addEventListener('click',saveManualShortlink);
		cancelBtn.addEventListener('click',function(){
			if(!currentScan)return;
			stopped=true;if(requestController){try{requestController.abort();}catch(e){}}
			var s=currentScan;currentScan=null;statusEl.textContent='Cancelling scan…';
			post({action:'awm_scan_cancel',nonce:nonce,scan_id:s.scan_id},10000).then(function(){setBusy(false);statusEl.textContent='Scan cancelled safely. Recent Changes checkpoint was not advanced.';}).catch(function(){setBusy(false);statusEl.textContent='Scan stopped in this browser. The server lock will expire automatically if needed.';});
		});
	})();
