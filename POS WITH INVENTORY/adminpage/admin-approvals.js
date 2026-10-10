(() => {
  const endpoint = "../api/admin-account-requests.php";

  async function requestJson(method, body, csrfToken) {
    const headers = new Headers({ Accept: "application/json" });
    const options = { method, headers, credentials: "same-origin" };
    if (body) {
      headers.set("Content-Type", "application/json");
      headers.set("X-CSRF-Token", csrfToken);
      options.body = JSON.stringify(body);
    }
    const response = await fetch(endpoint, options);
    const result = await response.json();
    if (response.status === 401) {
      window.location.href = "Login.html?error=session";
    }
    if (!response.ok) {
      throw new Error(result.error || "The request could not be completed.");
    }
    return result;
  }

  function renderRequests(requests, setFeedback) {
    const root = document.getElementById("admin-approval-root");
    root.replaceChildren();

    const section = document.createElement("section");
    section.className = "admin-approval-section";
    section.setAttribute("aria-labelledby", "admin-approval-title");

    const heading = document.createElement("div");
    heading.className = "products-heading";
    const copy = document.createElement("div");
    const title = document.createElement("h3");
    title.id = "admin-approval-title";
    title.textContent = "Administrator approval requests";
    const description = document.createElement("p");
    description.className = "chart-caption";
    description.textContent = "Approve only people authorized to access the administrator portal.";
    copy.append(title, description);
    const count = document.createElement("span");
    count.className = "admin-request-count";
    count.textContent = `${requests.length} pending`;
    heading.append(copy, count);

    const tableScroll = document.createElement("div");
    tableScroll.className = "table-scroll";
    const table = document.createElement("table");
    const thead = document.createElement("thead");
    const headerRow = document.createElement("tr");
    for (const label of ["Name", "Username", "Requested", "Actions"]) {
      const cell = document.createElement("th");
      cell.scope = "col";
      cell.textContent = label;
      headerRow.append(cell);
    }
    thead.append(headerRow);
    const tbody = document.createElement("tbody");

    if (requests.length === 0) {
      const row = document.createElement("tr");
      const cell = document.createElement("td");
      cell.colSpan = 4;
      cell.className = "empty-row";
      cell.textContent = "No pending administrator requests.";
      row.append(cell);
      tbody.append(row);
    }

    for (const request of requests) {
      const row = document.createElement("tr");
      for (const value of [request.display_name, request.username]) {
        const cell = document.createElement("td");
        cell.textContent = value;
        row.append(cell);
      }
      const date = new Date(request.created_at.replace(" ", "T"));
      const dateCell = document.createElement("td");
      dateCell.textContent = Number.isNaN(date.getTime())
        ? request.created_at
        : date.toLocaleDateString();
      row.append(dateCell);

      const actionsCell = document.createElement("td");
      const actions = document.createElement("div");
      actions.className = "admin-request-actions";
      for (const option of [
        { action: "approve", label: "Approve", className: "approve-admin-request-button" },
        { action: "reject", label: "Reject", className: "reject-admin-request-button" }
      ]) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = option.className;
        button.textContent = option.label;
        button.addEventListener("click", async () => {
          if (option.action === "reject"
            && !window.confirm(`Reject the administrator request from ${request.display_name}?`)) return;
          button.disabled = true;
          try {
            await requestJson("POST", { action: option.action, requestId: Number(request.id) }, window.adminApprovalsCsrfToken);
            setFeedback(option.action === "approve"
              ? `${request.display_name} is now an administrator.`
              : `Administrator request from ${request.display_name} rejected.`);
            await window.loadAdminUsers(false);
            await refresh(setFeedback);
          } catch (error) {
            setFeedback(error.message, true);
            button.disabled = false;
          }
        });
        actions.append(button);
      }
      actionsCell.append(actions);
      row.append(actionsCell);
      tbody.append(row);
    }

    table.append(thead, tbody);
    tableScroll.append(table);
    section.append(heading, tableScroll);
    root.append(section);
  }

  async function refresh(setFeedback) {
    const root = document.getElementById("admin-approval-root");
    try {
      const result = await requestJson("GET");
      renderRequests(result.requests || [], setFeedback);
    } catch (error) {
      root.textContent = error.message;
    }
  }

  window.AdminAccountApprovals = { refresh };
})();