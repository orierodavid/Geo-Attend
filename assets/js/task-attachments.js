(function(){
    'use strict';
    if (!window.GeoAttendAttachments || !window.GeoAttendAttachments.api) return;
    function esc(value){var d=document.createElement('div');d.textContent=String(value||'');return d.innerHTML;}
    function load(card){
        var idInput=card.querySelector('input[name="task_id"]');
        if(!idInput) return;
        var taskId=idInput.value;
        fetch(window.GeoAttendAttachments.api+'/staff/tasks/'+encodeURIComponent(taskId)+'/attachments',{headers:{'X-WP-Nonce':window.GeoAttendAttachments.nonce},credentials:'same-origin'}).then(function(r){return r.ok?r.json():[]}).then(function(files){
            if(!Array.isArray(files)||!files.length)return;
            var wrap=document.createElement('div');
            wrap.className='geo-task-attachments';
            wrap.innerHTML='<span class="geo-task-attachments-title">Attachments</span>';
            files.forEach(function(file){
                var link=document.createElement('a');
                link.className='geo-task-attachment';
                link.href=file.download_url+(file.download_url.indexOf('?')===-1?'?':'&')+'_wpnonce='+encodeURIComponent(window.GeoAttendAttachments.nonce);
                link.setAttribute('download','');
                link.innerHTML='<span class="geo-task-attachment-name">'+esc(file.original_name)+'</span><span class="geo-task-attachment-size">'+esc(file.size_label)+'</span>';
                wrap.appendChild(link);
            });
            var main=card.querySelector('.geo-task-main');
            if(main) main.appendChild(wrap);
        }).catch(function(){});
    }
    function init(){document.querySelectorAll('.geo-task').forEach(load);}
    if(document.readyState!=='loading')init();else document.addEventListener('DOMContentLoaded',init);
})();
