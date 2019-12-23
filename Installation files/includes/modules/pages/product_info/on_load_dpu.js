try { 
  init();
} catch(dpu_err) { 
  if (typeof(console.log) == 'function') {
    console.log(dpu_err.stack);
  }
}