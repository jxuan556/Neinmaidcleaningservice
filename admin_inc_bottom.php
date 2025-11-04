  </main>

  <footer class="footer">
    <div class="footer-inner">
      <div class="copyright">
        <span>©</span><small>2025 Copyright: <a href="#" style="color:#fff;text-decoration:underline">Neinmaidservice.com</a></small>
      </div>
    </div>
  </footer>

  <script>
    // profile dropdown
    const avatarBtn = document.getElementById('avatarBtn');
    const dropdown  = document.getElementById('dropdown');
    if (avatarBtn && dropdown) {
      avatarBtn.addEventListener('click', ()=> dropdown.style.display = dropdown.style.display==='block'?'none':'block');
      document.addEventListener('click', (e)=>{
        if(!dropdown.contains(e.target) && !avatarBtn.contains(e.target)){ dropdown.style.display='none'; }
      });
    }

    // basic modal system (used by pages below)
    function openModal(id){ const m=document.getElementById(id); if(m){ m.style.display='block'; } }
    function closeModal(id){ const m=document.getElementById(id); if(m){ m.style.display='none'; } }
  </script>
</body>
</html>
