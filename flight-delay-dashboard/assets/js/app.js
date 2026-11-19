/* FlightSense v4: data-driven model charts with an offline canvas fallback. */
document.addEventListener('DOMContentLoaded', () => {
  const colors = { decision_tree: '#f0b34b', random_forest: '#4cba8a', logistic_regression: '#8b72df' };
  const navy = '#284b73', muted = '#7288a0';
  const report = window.FLIGHT_VALIDATION || null;
  const hasChart = typeof window.Chart !== 'undefined';
  const percent = x => `${(Number(x) * 100).toFixed(1)}%`;
  const filter = document.querySelector('[data-table-filter]');
  if (filter) filter.addEventListener('input', () => {
    document.querySelectorAll('#flightTable tbody tr').forEach(row => {
      row.style.display = row.innerText.toLowerCase().includes(filter.value.toLowerCase()) ? '' : 'none';
    });
  });

  function surface(canvas) {
    const rect = canvas.parentElement.getBoundingClientRect();
    const width = Math.max(240, Math.round(rect.width));
    const height = Math.max(190, Math.round(rect.height));
    const dpi = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(width * dpi);
    canvas.height = Math.round(height * dpi);
    const c = canvas.getContext('2d');
    c.setTransform(dpi, 0, 0, dpi, 0, 0);
    c.clearRect(0, 0, width, height);
    c.font = '11px Arial,sans-serif';
    c.fillStyle = muted;
    return { c, width, height };
  }
  function roundedBar(c, x, y, w, h, color) {
    c.fillStyle = color;
    c.beginPath();
    c.roundRect(x, y, Math.max(0, w), h, 4);
    c.fill();
  }
  function nativeBar(canvas, labels, numbers, color) {
    const { c, width: w, height: h } = surface(canvas);
    const x = Math.min(150, Math.max(106, w * .36)), end = w - 45;
    const top = 24, step = (h - top - 12) / labels.length;
    labels.forEach((name, i) => {
      const y = top + i * step + (step - 16) / 2;
      const value = Math.max(0, Math.min(1, Number(numbers[i])));
      c.fillStyle = navy;
      c.font = '11px Arial,sans-serif';
      c.fillText(name, 8, y + 11);
      roundedBar(c, x, y, end - x, 16, '#edf1f7');
      if (value > 0) roundedBar(c, x, y, (end - x) * value, 16, color);
      c.fillStyle = navy;
      c.font = 'bold 10px Arial,sans-serif';
      c.fillText(percent(value), end + 5, y + 11);
    });
  }
  function nativeLine(canvas, labels, nums) {
    const { c, width: w, height: h } = surface(canvas);
    const l = 44, r = 15, t = 24, b = h - 32;
    c.strokeStyle = '#e7edf5'; c.lineWidth = 1;
    for (let i=0; i<=4; i++) { const y=b-(b-t)*i/4; c.beginPath(); c.moveTo(l,y); c.lineTo(w-r,y); c.stroke(); c.fillStyle=muted; c.fillText(`${i*25}%`,5,y+3); }
    c.beginPath();
    nums.forEach((v,i) => { const x = l+(w-l-r)*i/(nums.length-1), y = b-(b-t)*v/100; i ? c.lineTo(x,y) : c.moveTo(x,y); });
    c.strokeStyle='#3879e8';c.lineWidth=3;c.stroke();
    labels.forEach((label,i)=>{const x=l+(w-l-r)*i/(labels.length-1);c.fillStyle=muted;c.fillText(label,x-10,h-12);});
  }
  function nativeDoughnut(canvas, labels, values, palette) {
    const { c, width:w, height:h } = surface(canvas);
    const radius=Math.min(86,h*.35,w*.25), cx=Math.max(radius+12,w*.33),cy=h*.48;
    let angle=-Math.PI/2;
    const sum=values.reduce((a,b)=>a+b,0);
    values.forEach((v,i)=>{const newAngle=angle+2*Math.PI*v/sum;c.beginPath();c.arc(cx,cy,radius,angle,newAngle);c.arc(cx,cy,radius*.58,newAngle,angle,true);c.closePath();c.fillStyle=palette[i];c.fill();angle=newAngle;});
    labels.forEach((label,i)=>{const x=w*.61,y=h*.28+i*28;c.fillStyle=palette[i];c.fillRect(x,y,10,10);c.fillStyle=navy;c.fillText(`${label}: ${values[i]}%`,x+17,y+9);});
  }
  function nativeRoc(canvas, models) {
    const { c, width:w, height:h } = surface(canvas);
    const l=42,r=20,t=18,b=h-35;
    c.font='10px Arial,sans-serif';c.strokeStyle='#e5ebf3';c.lineWidth=1;
    for(let i=0;i<=5;i++) {const y=b-(b-t)*i/5,x=l+(w-l-r)*i/5;c.beginPath();c.moveTo(l,y);c.lineTo(w-r,y);c.moveTo(x,t);c.lineTo(x,b);c.stroke();c.fillStyle=muted;c.fillText((i/5).toFixed(1),9,y+4);c.fillText((i/5).toFixed(1),x-8,b+15);}
    c.strokeStyle='#aebac8';c.setLineDash([5,5]);c.beginPath();c.moveTo(l,b);c.lineTo(w-r,t);c.stroke();c.setLineDash([]);
    Object.entries(models).forEach(([id, model])=>{
      c.beginPath();model.roc.forEach((point,i)=>{const x=l+point.x*(w-l-r),y=b-point.y*(b-t);i?c.lineTo(x,y):c.moveTo(x,y);});
      c.strokeStyle=colors[id]||'#287be6';c.lineWidth=2.8;c.stroke();
    });
    c.fillStyle=navy;c.fillText('False positive rate →',w/2-45,h-4);
  }
  function chartOrFallback(canvasId, makeChart, fallback) {
    const canvas=document.getElementById(canvasId);
    if(!canvas) return;
    if(hasChart) { makeChart(canvas); }
    else { fallback(canvas); fallbackRenderers.push(()=>fallback(canvas)); }
  }
  const fallbackRenderers=[];
  const risk=document.getElementById('riskChart');
  if(risk) chartOrFallback('riskChart', canvas=>new Chart(canvas,{type:'line',data:{labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],datasets:[{data:[32,41,38,55,47,62,51],borderColor:'#3879e8',backgroundColor:'rgba(56,121,232,.12)',fill:true,tension:.4,pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{min:0,max:100,ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}}}}), canvas=>nativeLine(canvas,['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],[32,41,38,55,47,62,51]));
  const cause=document.getElementById('causeChart');
  if(cause) chartOrFallback('causeChart',canvas=>new Chart(canvas,{type:'doughnut',data:{labels:['Weather','Air traffic','History','Other'],datasets:[{data:[42,27,21,10],backgroundColor:['#3879e8','#53c18a','#f6b94a','#d9e1eb'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'70%',plugins:{legend:{position:'right',labels:{usePointStyle:true,boxWidth:8,color:navy}}}}}),canvas=>nativeDoughnut(canvas,['Weather','Air traffic','History','Other'],[42,27,21,10],['#3879e8','#53c18a','#f6b94a','#d9e1eb']));

  if(report && report.models) {
    const entries=Object.entries(report.models);
    chartOrFallback('algorithmAccuracyChart',canvas=>new Chart(canvas,{
      type:'bar',data:{labels:entries.map(([,m])=>m.name),datasets:[{label:'Holdout accuracy',data:entries.map(([,m])=>+(m.metrics.accuracy*100).toFixed(1)),backgroundColor:entries.map(([id])=>colors[id]),borderRadius:6,barThickness:37}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>`${c.raw}% accuracy`}}},scales:{y:{min:0,max:100,ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}}}
    }),canvas=>nativeBar(canvas,entries.map(([,m])=>m.name),entries.map(([,m])=>m.metrics.accuracy),'#3879e8'));

    chartOrFallback('algorithmRocChart',canvas=>new Chart(canvas,{
      type:'line',data:{datasets:entries.map(([id,m])=>({label:`${m.name} (AUC ${m.metrics.roc_auc.toFixed(3)})`,data:m.roc,borderColor:colors[id],pointRadius:0,borderWidth:2.5,tension:0}))
        .concat([{label:'Random baseline',data:[{x:0,y:0},{x:1,y:1}],borderColor:'#adb9c7',pointRadius:0,borderDash:[5,5],borderWidth:1}])},
      options:{responsive:true,maintainAspectRatio:false,parsing:false,plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:10}}}},scales:{x:{type:'linear',min:0,max:1,title:{display:true,text:'False positive rate'}},y:{min:0,max:1,title:{display:true,text:'True positive rate'}}}}
    }),canvas=>nativeRoc(canvas,report.models));

    const post=report.mode_reports && report.mode_reports.after_departure;
    if (post && post.models) {
      const items=Object.entries(post.models);
      chartOrFallback('postAccuracyChart',canvas=>new Chart(canvas,{type:'bar',
        data:{labels:items.map(([,m])=>m.name),datasets:[{data:items.map(([,m])=>+(m.metrics.accuracy*100).toFixed(1)),backgroundColor:items.map(([id])=>colors[id]),borderRadius:6}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{min:0,max:100,ticks:{callback:v=>v+'%'}}}}}),
        canvas=>nativeBar(canvas,items.map(([,m])=>m.name),items.map(([,m])=>m.metrics.accuracy),'#3879e8'));
      chartOrFallback('postRocChart',canvas=>new Chart(canvas,{type:'line',
        data:{datasets:items.map(([id,m])=>({label:`${m.name} (AUC ${m.metrics.roc_auc.toFixed(3)})`,data:m.roc,borderColor:colors[id],pointRadius:0,borderWidth:2.5,tension:0}))},
        options:{responsive:true,maintainAspectRatio:false,parsing:false,plugins:{legend:{position:'bottom'}},scales:{x:{type:'linear',min:0,max:1},y:{min:0,max:1}}}}),
        canvas=>nativeRoc(canvas,post.models));
    }
    const keys=['accuracy','precision','recall','specificity','f1','balanced_accuracy'];
    const labels=['Accuracy','Precision','Recall','Specificity','F1 score','Balanced acc.'];
    entries.forEach(([id,m])=>{
      chartOrFallback(`metricChart-${id}`,canvas=>new Chart(canvas,{
        type:'bar',data:{labels,datasets:[{data:keys.map(k=>+(m.metrics[k]*100).toFixed(1)),backgroundColor:colors[id],borderRadius:4,barThickness:10}]},
        options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>`${c.raw}%`}}},scales:{x:{min:0,max:100,ticks:{callback:v=>v+'%'}},y:{grid:{display:false},ticks:{font:{size:10}}}}}
      }),canvas=>nativeBar(canvas,labels,keys.map(k=>m.metrics[k]),colors[id]));
    });
  }
  if(fallbackRenderers.length) {
    let resizeQueued=false;
    window.addEventListener('resize',()=>{
      if(resizeQueued) return;
      resizeQueued=true;
      requestAnimationFrame(()=>{fallbackRenderers.forEach(draw=>draw());resizeQueued=false;});
    });
  }
});
