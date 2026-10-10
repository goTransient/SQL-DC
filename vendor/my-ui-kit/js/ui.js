(function(){
  const t=(key,values)=>window.UI18n.t(key,values);
  const $=(s,root=document)=>root.querySelector(s);
  const $$=(s,root=document)=>Array.from(root.querySelectorAll(s));
  $$('[data-sidebar-toggle]').forEach(btn=>btn.addEventListener('click',()=>$('#uiSidebar')?.classList.toggle('is-open')));
  $$('[data-modal-open]').forEach(btn=>btn.addEventListener('click',()=>{const modal=document.getElementById(btn.dataset.modalOpen);if(modal){modal.hidden=false;modal.querySelector('[data-modal-close]')?.focus();}}));
  $$('[data-modal-close]').forEach(btn=>btn.addEventListener('click',()=>btn.closest('.ui-modal-backdrop').hidden=true));
  $$('.ui-modal-backdrop').forEach(modal=>modal.addEventListener('click',e=>{if(e.target===modal)modal.hidden=true;}));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')$$('.ui-modal-backdrop:not([hidden])').forEach(modal=>modal.hidden=true);});
  $$('[data-dropdown-toggle]').forEach(btn=>btn.addEventListener('click',()=>{const menu=document.getElementById(btn.dataset.dropdownToggle);if(menu)menu.hidden=!menu.hidden;}));
  $$('[data-copy-text]').forEach(btn=>btn.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(btn.dataset.copyText);const status=$('#copyStatus');if(status)status.textContent=t('copy.success');const old=btn.textContent;btn.textContent=t('copy.button');setTimeout(()=>btn.textContent=old,1300);}catch{const status=$('#copyStatus');if(status)status.textContent=t('copy.unavailable');}}));
  $$('[data-demo-form]').forEach(form=>form.addEventListener('submit',e=>{e.preventDefault();const status=$('[data-form-status]',form);if(status)status.textContent=t('form.demoSubmitted');}));
  $$('table.ui-table[data-pagination]').forEach((table,index)=>{
    const panel=table.closest('.ui-panel');
    const tableWrap=table.closest('.ui-table-wrap');
    const footer=panel.querySelector('.ui-pagination');
    const search=panel.querySelector('.ui-search');
    const rows=Array.from(table.tBodies[0].rows);
    const pageSizes=[50,100,200];
    let pageSize=50;
    let currentPage=1;
    let query='';
    let statusFilter='';
    const statusSelect=panel.querySelector('.ui-toolbar .ui-select');
    const controls=document.createElement('div');
    controls.className='ui-table-page-size';
    const label=document.createElement('label');
    const select=document.createElement('select');
    select.className='ui-select';
    select.id=`ui-page-size-${index+1}`;
    label.htmlFor=select.id;
    label.dataset.i18n='table.pageSize';
    label.textContent=t('table.pageSize');
    pageSizes.forEach(size=>{
      const option=document.createElement('option');
      option.value=String(size);
      option.textContent=String(size);
      select.append(option);
    });
    const pageNumbers=document.createElement('div');
    pageNumbers.className='ui-page-numbers';
    controls.append(label,select,pageNumbers);
    tableWrap.parentNode.insertBefore(controls,tableWrap);

    const summary=document.createElement('span');
    footer.replaceChildren(summary);

    function render(){
      const filteredRows=rows.filter(row=>{
        const matchesQuery=!query||row.textContent.toLowerCase().includes(query);
        const status=row.querySelector('.ui-badge')?.textContent.trim();
        const matchesStatus=!statusFilter||status===statusFilter;
        return matchesQuery&&matchesStatus;
      });
      const pageCount=Math.max(1,Math.ceil(filteredRows.length/pageSize));
      currentPage=Math.min(currentPage,pageCount);
      const start=(currentPage-1)*pageSize;
      const visibleRows=new Set(filteredRows.slice(start,start+pageSize));
      rows.forEach(row=>{row.hidden=!visibleRows.has(row);});
      const first=filteredRows.length?start+1:0;
      const last=Math.min(start+pageSize,filteredRows.length);
      summary.textContent=t('table.summary',{first,last,total:filteredRows.length});

      pageNumbers.replaceChildren();
      const previous=document.createElement('button');
      previous.className='ui-page-number';
      previous.type='button';
      previous.textContent='‹';
      previous.setAttribute('aria-label',t('table.previousPage'));
      previous.disabled=currentPage===1;
      previous.addEventListener('click',()=>{currentPage--;render();});
      pageNumbers.append(previous);
      for(let page=1;page<=pageCount;page++){
        const button=document.createElement('button');
        button.className='ui-page-number';
        button.type='button';
        button.textContent=String(page);
        button.setAttribute('aria-label',t('table.page',{page}));
        if(page===currentPage){
          button.classList.add('active');
          button.setAttribute('aria-current','page');
        }
        button.addEventListener('click',()=>{currentPage=page;render();});
        pageNumbers.append(button);
      }
      const next=document.createElement('button');
      next.className='ui-page-number';
      next.type='button';
      next.textContent='›';
      next.setAttribute('aria-label',t('table.nextPage'));
      next.disabled=currentPage===pageCount;
      next.addEventListener('click',()=>{currentPage++;render();});
      pageNumbers.append(next);
    }

    select.addEventListener('change',()=>{
      pageSize=Number(select.value);
      currentPage=1;
      render();
    });
    if(search){
      search.addEventListener('input',()=>{
        query=search.value.trim().toLowerCase();
        currentPage=1;
        render();
      });
    }
    if(statusSelect){
      statusSelect.addEventListener('change',()=>{
        statusFilter=statusSelect.value;
        currentPage=1;
        render();
      });
    }
    render();
    document.addEventListener('ui-language-change',render);
  });
})();
