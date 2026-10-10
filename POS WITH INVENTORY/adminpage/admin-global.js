(() => {
  const icons = {
    dashboard: '<rect x="2.5" y="2.5" width="8" height="8" rx="2" fill="currentColor" opacity=".8"/><rect x="13.5" y="2.5" width="8" height="8" rx="2" fill="currentColor" opacity=".55"/><rect x="2.5" y="13.5" width="8" height="8" rx="2" fill="currentColor" opacity=".55"/><rect x="13.5" y="13.5" width="8" height="8" rx="2" fill="currentColor" opacity=".8"/>',
    management: '<circle cx="12" cy="7" r="4" fill="currentColor" opacity=".48" stroke="currentColor" stroke-width="1.4"/><path d="M3 21c.6-4.4 3.7-7 9-7s8.4 2.6 9 7" fill="currentColor" opacity=".35" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
    products: '<path d="m2 7 10-5 10 5-10 5L2 7Z" fill="currentColor" opacity=".35" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M2 7v10l10 5V12M22 7v10l-10 5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>',
    sales: '<path d="M4 4h16v16H4z" fill="currentColor" opacity=".18" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8 8h8M8 12h8M8 16h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M7 2v4m10-4v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
    purchases: '<path d="M2 3h2l2.5 13h12L21 7H5" fill="currentColor" opacity=".22" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="9" cy="20" r="1.7" fill="currentColor"/><circle cx="18" cy="20" r="1.7" fill="currentColor"/>',
    reports: '<rect x="3" y="2" width="14" height="20" rx="2" fill="currentColor" opacity=".22" stroke="currentColor" stroke-width="1.4"/><path d="M7 7h6m-6 4h6m-6 4h3m8-7h4v12h-4zM8 19v-2m3 2v-5m3 5v-8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
  };

  const page = location.pathname.split("/").pop().toLowerCase();
  const currentPage = page === "usermanagement.html" ? "management" : page.replace(".html", "");
  const items = [
    { label: "Dashboard", key: "dashboard", href: "admindashboard.html#dashboard-view", view: "dashboard-view" },
    { label: "User Management", key: "management", href: "admindashboard.html#user-management", view: "user-management" },
    { label: "Products", key: "products", href: "products.html" },
    { label: "Sales", key: "sales", href: "sales.html" },
    { label: "Purchases", key: "purchases", href: "purchases.html" },
    { label: "Reports", key: "reports", href: "reports.html" }
  ];
  const nav = document.querySelector(".sidebar .nav, .sidebar nav");
  if (nav) {
    nav.replaceChildren(...items.map(item => {
      const link = document.createElement("a");
      const key = item.key === "management" ? "user-management" : item.key;
      const selectedDashboardView = currentPage === "admindashboard" && location.hash === "#user-management";
      const active = currentPage === key || (item.key === "management" && selectedDashboardView)
        || (item.key === "dashboard" && currentPage === "admindashboard" && !selectedDashboardView);
      link.className = `nav-link${active ? " active" : ""}`;
      link.href = item.href;
      if (page === "admindashboard.html" && item.view) link.dataset.view = item.view;
      if (active) link.setAttribute("aria-current", "page");
      const icon = document.createElement("span");
      icon.className = "nav-icon";
      icon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">${icons[item.key]}</svg>`;
      const label = document.createElement("span");
      label.textContent = item.label;
      link.append(icon, label);
      if (item.key !== "dashboard") {
        const arrow = document.createElement("span");
        arrow.className = "nav-arrow";
        arrow.textContent = "›";
        link.append(arrow);
      }
      return link;
    }));
  }

  document.querySelectorAll("time.today").forEach(time => {
    time.textContent = new Intl.DateTimeFormat("en", {
      month: "long",
      day: "numeric",
      year: "numeric"
    }).format(new Date());
  });
})();
