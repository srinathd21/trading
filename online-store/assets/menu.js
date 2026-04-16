function initMobileMenu() {
  const mobileMenu = document.getElementById('mobileMenu');
  if (!mobileMenu) return;

  const mobileMenuLinks = mobileMenu.querySelectorAll('.mobile-menu-links a');

  mobileMenuLinks.forEach(link => {
    link.addEventListener('click', function () {
      const offcanvasInstance = bootstrap.Offcanvas.getInstance(mobileMenu);
      if (offcanvasInstance) {
        offcanvasInstance.hide();
      }
    });
  });
}