	(function(){
		'use strict';
		var select=document.getElementById('awm-approved-select'),phone=document.getElementById('awm-phone'),source=document.getElementById('awm-source'),message=document.getElementById('awm-message'),title=document.getElementById('awm-title'),urlOut=document.getElementById('awm-generated-url'),shortOut=document.getElementById('awm-generated-shortcode'),htmlOut=document.getElementById('awm-generated-html');
		function escAttr(s){return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
		function shortcodeAttr(s){return escAttr(s);}
		function update(){
			var p=(phone.value||'').replace(/\D/g,'');
			if(!p){urlOut.textContent='Enter a phone number.';shortOut.textContent='—';htmlOut.textContent='—';return;}
			var msg=message.value||'',src=(source.value||'').trim(),linkTitle=title.value||'Chat on WhatsApp',u='https://wa.me/'+p+(msg?'?text='+encodeURIComponent(msg):'');
			urlOut.textContent=u;
			shortOut.textContent='[whatsapp-link title="'+shortcodeAttr(linkTitle)+'" phone="'+p+'" pretext="'+shortcodeAttr(msg)+'"'+(src?' source="'+shortcodeAttr(src)+'"':'')+']';
			htmlOut.textContent='<a href="'+escAttr(u)+'" target="_blank" rel="noopener noreferrer"'+(src?' data-awm-source="'+escAttr(src)+'"':'')+'>'+escAttr(linkTitle)+'</a>';
		}
		select.addEventListener('change',function(){var opt=this.options[this.selectedIndex];if(this.value){phone.value=this.value;source.value=opt.getAttribute('data-source')||'';}update();});
		[phone,source,message,title].forEach(function(el){el.addEventListener('input',update);});
		document.querySelectorAll('[data-copy]').forEach(function(btn){btn.addEventListener('click',function(){var el=document.getElementById(this.getAttribute('data-copy')),original=this.getAttribute('data-copy-label')||'Copy';if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(el.textContent||'');}this.textContent='Copied';var b=this;setTimeout(function(){b.textContent=original;},1200);});});update();
	})();
