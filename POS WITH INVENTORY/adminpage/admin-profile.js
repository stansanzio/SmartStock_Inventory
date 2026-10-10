let adminProfile = null;

window.renderAdminProfile = function (user) {
  adminProfile = { ...adminProfile, ...user };
  user = adminProfile;
  const avatarUrl = user.avatarUrl || "../api/avatar-image.php?id=1";
  const displayName = user.displayName || "";
  const avatar = document.getElementById("sidebar-avatar");
  if (avatar) {
    avatar.src = avatarUrl;
    avatar.alt = displayName ? `${displayName}'s avatar` : "User avatar";
  }
  const name = document.getElementById("sidebar-user-name");
  if (name) {
    name.replaceChildren(document.createTextNode(displayName));
    name.title = user.isEmployeeOfMonth ? `${displayName} — Employee of the Month` : displayName;
    if (user.isEmployeeOfMonth) {
      const star = document.createElement("span");
      star.className = "employee-of-month-star";
      star.textContent = " ⭐";
      star.setAttribute("aria-label", "Employee of the Month");
      name.append(star);
    }
  }
  const role = document.getElementById("sidebar-user-role");
  if (role) {
    role.textContent = user.role === "owner" ? "Owner" : user.role === "admin" ? "Admin" : "Employee";
    role.hidden = false;
    role.classList.toggle("owner", user.role === "owner");
  }
  const switchView = document.getElementById("owner-switch-view");
  if (switchView) switchView.hidden = user.role !== "owner";
};

(() => {
  let refreshInProgress = false;

  async function refreshEmployeeOfMonth() {
    if (!adminProfile || refreshInProgress || document.hidden) return;
    refreshInProgress = true;
    try {
      const response = await fetch("../api/employee-of-month.php", { credentials: "same-origin" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Unable to refresh Employee of the Month.");
      adminProfile.isEmployeeOfMonth = result.employeeOfMonth !== null
        && Number(result.employeeOfMonth.id) === Number(adminProfile.id);
      window.renderAdminProfile(adminProfile);
    } catch (error) {
      console.warn("Employee of the Month profile refresh failed.", error);
    } finally {
      refreshInProgress = false;
    }
  }

  if ("BroadcastChannel" in window) {
    const channel = new BroadcastChannel("smartstock-sales");
    channel.addEventListener("message", event => {
      if (event.data?.type === "sale-completed") void refreshEmployeeOfMonth();
    });
  }
  window.addEventListener("storage", event => {
    if (event.key === "smartstock-sale-completed" && event.newValue) void refreshEmployeeOfMonth();
  });
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) void refreshEmployeeOfMonth();
  });
  window.setInterval(() => void refreshEmployeeOfMonth(), 45000);
})();
