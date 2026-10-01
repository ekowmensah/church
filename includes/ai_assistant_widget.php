<?php
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

$assistantWidgetAllowed = !empty($_SESSION['user_id'])
    && (is_super_admin() || has_permission('use_dashboard_insights'));
if (!$assistantWidgetAllowed) {
    return;
}
?>
<style>
.mf-ai-launcher{position:fixed;right:24px;bottom:24px;width:58px;height:58px;border:0;border-radius:50%;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;box-shadow:0 12px 30px rgba(37,99,235,.35);z-index:2050;font-size:23px;transition:.2s}
.mf-ai-launcher:hover{transform:translateY(-2px);color:#fff}.mf-ai-launcher .badge{position:absolute;right:-3px;top:-3px;border:2px solid #fff}
.mf-ai-panel{position:fixed;right:24px;bottom:94px;width:min(420px,calc(100vw - 28px));height:min(690px,calc(100vh - 120px));background:#fff;border-radius:20px;box-shadow:0 24px 70px rgba(15,23,42,.28);z-index:2051;display:none;overflow:hidden;border:1px solid #e2e8f0}
.mf-ai-panel.open{display:flex}.mf-ai-shell{display:flex;flex-direction:column;width:100%;min-width:0}.mf-ai-head{background:linear-gradient(135deg,#173f5f,#2563eb);color:#fff;padding:16px;display:flex;align-items:center;gap:12px}
.mf-ai-avatar{width:42px;height:42px;border-radius:14px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:20px}.mf-ai-title{flex:1;min-width:0}.mf-ai-title strong{display:block}.mf-ai-title small{opacity:.82}.mf-ai-head button{border:0;background:transparent;color:#fff;padding:6px}
.mf-ai-history{position:absolute;inset:0 54px 0 0;width:82%;max-width:320px;background:#fff;z-index:5;box-shadow:8px 0 30px rgba(15,23,42,.18);display:none;flex-direction:column}.mf-ai-history.open{display:flex}.mf-ai-history-head{padding:18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}.mf-ai-conversations{overflow:auto;padding:8px;flex:1}.mf-ai-conversation{display:block;width:100%;border:0;background:#fff;text-align:left;padding:11px;border-radius:10px;color:#334155}.mf-ai-conversation:hover{background:#f1f5f9}.mf-ai-conversation small{display:block;color:#94a3b8;margin-top:3px}
.mf-ai-messages{flex:1;overflow-y:auto;padding:18px;background:#f8fafc}.mf-ai-empty{text-align:center;color:#64748b;padding:52px 18px}.mf-ai-empty i{display:block;font-size:38px;color:#6366f1;margin-bottom:14px}.mf-ai-msg{max-width:86%;padding:11px 13px;border-radius:16px;margin-bottom:12px;white-space:pre-wrap;line-height:1.45;overflow-wrap:anywhere}.mf-ai-msg.user{margin-left:auto;background:#2563eb;color:#fff;border-bottom-right-radius:5px}.mf-ai-msg.assistant{background:#fff;color:#1e293b;border:1px solid #e2e8f0;border-bottom-left-radius:5px}.mf-ai-msg .meta{display:block;font-size:10px;opacity:.65;margin-top:5px}.mf-ai-local{margin-top:9px;padding-top:9px;border-top:1px dashed #cbd5e1;font-size:12px;color:#475569}.mf-ai-notice{font-size:12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:8px 10px;border-radius:10px;margin-bottom:10px}
.mf-ai-typing{display:none;padding:0 18px 8px;background:#f8fafc;color:#64748b;font-size:12px}.mf-ai-typing.show{display:block}.mf-ai-compose{padding:12px;background:#fff;border-top:1px solid #e2e8f0}.mf-ai-compose form{display:flex;gap:8px;align-items:flex-end}.mf-ai-compose textarea{resize:none;max-height:100px;min-height:42px;border-radius:14px}.mf-ai-send{width:44px;height:44px;border-radius:14px;border:0;background:#2563eb;color:#fff;flex:0 0 auto}.mf-ai-disclaimer{font-size:10px;color:#94a3b8;text-align:center;margin-top:7px}
@media(max-width:575.98px){.mf-ai-launcher{right:16px;bottom:16px}.mf-ai-panel{inset:0;width:100%;height:100%;max-height:none;border-radius:0}.mf-ai-history{inset:0;width:86%;max-width:none}}
</style>
<button type="button" class="mf-ai-launcher" id="mfAiLauncher" aria-label="Open MyFreeman Assistant">
    <i class="fas fa-comment-dots"></i><span class="badge badge-success">AI</span>
</button>
<section class="mf-ai-panel" id="mfAiPanel" aria-label="MyFreeman Assistant">
    <aside class="mf-ai-history" id="mfAiHistory">
        <div class="mf-ai-history-head"><strong>Conversations</strong><button class="btn btn-sm btn-light" id="mfAiHistoryClose"><i class="fas fa-times"></i></button></div>
        <div class="p-2"><button class="btn btn-primary btn-sm btn-block" id="mfAiNew"><i class="fas fa-plus mr-1"></i>New conversation</button></div>
        <div class="mf-ai-conversations" id="mfAiConversations"></div>
    </aside>
    <div class="mf-ai-shell">
        <header class="mf-ai-head">
            <button type="button" id="mfAiHistoryOpen" title="Conversation history"><i class="fas fa-bars"></i></button>
            <span class="mf-ai-avatar"><i class="fas fa-robot"></i></span>
            <span class="mf-ai-title"><strong>MyFreeman Assistant</strong><small>Permission-scoped system help</small></span>
            <button type="button" id="mfAiClose" title="Close"><i class="fas fa-times"></i></button>
        </header>
        <div class="mf-ai-messages" id="mfAiMessages">
            <div class="mf-ai-empty"><i class="fas fa-comments"></i><strong>How can I help?</strong><p class="mt-2 mb-0">Ask about payments, attendance, membership, events, health summaries, or birthdays.</p></div>
        </div>
        <div class="mf-ai-typing" id="mfAiTyping"><i class="fas fa-circle-notch fa-spin mr-1"></i>Checking authorized system information…</div>
        <div class="mf-ai-compose">
            <form id="mfAiForm">
                <textarea class="form-control" id="mfAiQuestion" rows="1" maxlength="500" placeholder="Type a message…" required></textarea>
                <button class="mf-ai-send" id="mfAiSend" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
            </form>
            <div class="mf-ai-disclaimer">Answers respect your existing access permissions. Verify critical decisions.</div>
        </div>
    </div>
</section>
<script>
(function(){
    const endpoint=<?= json_encode(BASE_URL . '/views/ajax_ai_assistant.php') ?>;
    const csrf=<?= json_encode(csrf_token()) ?>;
    const panel=document.getElementById('mfAiPanel'),launcher=document.getElementById('mfAiLauncher');
    const messages=document.getElementById('mfAiMessages'),question=document.getElementById('mfAiQuestion');
    const typing=document.getElementById('mfAiTyping'),send=document.getElementById('mfAiSend');
    const history=document.getElementById('mfAiHistory'),conversationList=document.getElementById('mfAiConversations');
    let conversationId=null,busy=false;
    function setOpen(open){panel.classList.toggle('open',open);if(open){loadConversations();setTimeout(()=>question.focus(),100)}}
    function clearEmpty(){const empty=messages.querySelector('.mf-ai-empty');if(empty)empty.remove()}
    function addMessage(text,type,meta,local,notice){clearEmpty();if(notice){const n=document.createElement('div');n.className='mf-ai-notice';n.textContent=notice;messages.appendChild(n)}const el=document.createElement('div');el.className='mf-ai-msg '+(type==='user'?'user':'assistant');el.textContent=text;if(local){const l=document.createElement('div');l.className='mf-ai-local';l.textContent='Verified system result: '+local;el.appendChild(l)}if(meta){const m=document.createElement('span');m.className='meta';m.textContent=meta;el.appendChild(m)}messages.appendChild(el);messages.scrollTop=messages.scrollHeight}
    async function request(url,options){const response=await fetch(url,options);let data;try{data=await response.json()}catch(e){throw new Error('The assistant returned an invalid response.')}if(!response.ok||!data.success)throw new Error(data.error||'Assistant request failed.');return data}
    async function loadConversations(){try{const data=await request(endpoint+'?action=list',{credentials:'same-origin'});conversationList.innerHTML='';data.conversations.forEach(c=>{const b=document.createElement('button');b.className='mf-ai-conversation';b.type='button';b.textContent=c.title;const s=document.createElement('small');s.textContent=c.last_message_at||c.created_at;b.appendChild(s);b.onclick=()=>loadHistory(c.id);conversationList.appendChild(b)})}catch(e){conversationList.textContent=e.message}}
    async function loadHistory(id){try{const data=await request(endpoint+'?action=history&conversation_id='+encodeURIComponent(id),{credentials:'same-origin'});conversationId=id;messages.innerHTML='';data.messages.forEach(m=>addMessage(m.message_text,m.sender==='user'?'user':'assistant',m.sender==='agent'?'AI Agent':'Local'));history.classList.remove('open')}catch(e){addMessage(e.message,'assistant','Error')}}
    async function ask(){const text=question.value.trim();if(busy||text.length<3)return;busy=true;question.value='';send.disabled=true;typing.classList.add('show');addMessage(text,'user','You');try{const body=new URLSearchParams({action:'ask',csrf_token:csrf,question:text});if(conversationId)body.append('conversation_id',conversationId);const data=await request(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},credentials:'same-origin',body});conversationId=data.conversation_id;addMessage(data.answer,'assistant',data.source==='agent'?'AI Agent':'Local',data.local_answer,data.notice);loadConversations()}catch(e){addMessage(e.message,'assistant','Error')}finally{busy=false;send.disabled=false;typing.classList.remove('show');question.focus()}}
    launcher.onclick=()=>setOpen(true);document.getElementById('mfAiClose').onclick=()=>setOpen(false);document.getElementById('mfAiHistoryOpen').onclick=()=>history.classList.add('open');document.getElementById('mfAiHistoryClose').onclick=()=>history.classList.remove('open');document.getElementById('mfAiNew').onclick=()=>{conversationId=null;messages.innerHTML='<div class="mf-ai-empty"><i class="fas fa-comments"></i><strong>New conversation</strong><p class="mt-2 mb-0">What would you like to know?</p></div>';history.classList.remove('open');question.focus()};document.getElementById('mfAiForm').onsubmit=e=>{e.preventDefault();ask()};question.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();ask()}});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&panel.classList.contains('open'))setOpen(false)});
})();
</script>
