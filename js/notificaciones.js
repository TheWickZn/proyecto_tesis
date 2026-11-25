(() => {
    const center = document.querySelector("[data-notification-center]");
    if (!center) return;

    const endpoint = center.dataset.endpoint;
    const toggle = center.querySelector("[data-notification-toggle]");
    const popover = center.querySelector("[data-notification-popover]");
    const count = center.querySelector("[data-notification-count]");
    const list = center.querySelector("[data-notification-list]");
    const error = center.querySelector("[data-notification-error]");
    const markAll = center.querySelector("[data-notification-mark-all]");
    let lastId = Number(center.dataset.lastId || 0);
    let cargando = false;
    let errorTemporizador;

    const formData = (values) => {
        const data = new URLSearchParams();
        Object.entries(values).forEach(([key, value]) => data.set(key, String(value)));
        return data;
    };

    const mostrarError = (message) => {
        error.textContent = message;
        error.hidden = false;
        window.clearTimeout(errorTemporizador);
        errorTemporizador = window.setTimeout(() => {
            error.hidden = true;
        }, 6000);
    };

    const solicitar = async (url, options) => {
        const response = await fetch(url, { credentials: "same-origin", ...options });
        const payload = await response.json();
        if (!response.ok) {
            throw new Error(payload.error || "No se pudo actualizar la bandeja de notificaciones.");
        }
        return payload;
    };

    const actualizarContador = (sinLeer) => {
        count.textContent = sinLeer > 99 ? "99+" : String(sinLeer);
        count.hidden = sinLeer === 0;
        markAll.disabled = sinLeer === 0;
        toggle.setAttribute(
            "aria-label",
            sinLeer > 0 ? `Notificaciones: ${sinLeer} sin leer` : "Notificaciones",
        );
    };

    const fechaLegible = (fecha) => {
        const valor = new Date(String(fecha).replace(" ", "T"));
        if (Number.isNaN(valor.getTime())) return String(fecha);
        return new Intl.DateTimeFormat("es", {
            dateStyle: "short",
            timeStyle: "short",
        }).format(valor);
    };

    const crearElemento = (notification, className) => {
        const link = document.createElement("a");
        link.className = className;
        if (notification.leida_en) link.classList.add("is-read");
        link.href = notification.url;
        link.dataset.notificationId = String(notification.id_notificacion);

        const copy = document.createElement("span");
        copy.className = "notification-item-copy";
        const message = document.createElement("strong");
        message.textContent = notification.mensaje;
        const date = document.createElement("small");
        date.textContent = fechaLegible(notification.fecha_registro);
        copy.append(message, date);
        link.append(copy);
        return link;
    };

    const mostrarToast = (notification) => {
        let stack = document.querySelector(".notification-toast-stack");
        if (!stack) {
            stack = document.createElement("div");
            stack.className = "notification-toast-stack";
            stack.setAttribute("aria-live", "polite");
            stack.setAttribute("aria-relevant", "additions");
            document.body.append(stack);
        }

        const toast = crearElemento(notification, "notification-toast");
        toast.setAttribute("role", "status");
        stack.prepend(toast);
        window.setTimeout(() => toast.remove(), 10000);
    };

    const renderizar = (notifications) => {
        list.replaceChildren();
        if (notifications.length === 0) {
            const empty = document.createElement("p");
            empty.className = "notification-empty";
            empty.textContent = "Todavía no hay prospectos ni clientes nuevos.";
            list.append(empty);
            return;
        }
        notifications.forEach((notification) => {
            list.append(crearElemento(notification, "notification-item"));
        });
    };

    const cargar = async () => {
        if (cargando) return;
        cargando = true;
        try {
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set("action", "listar");
            url.searchParams.set("desde", String(lastId));
            const payload = await solicitar(url);
            actualizarContador(payload.sin_leer);
            renderizar(payload.notificaciones);
            payload.nuevas.forEach((notification) => {
                lastId = Math.max(lastId, Number(notification.id_notificacion));
                mostrarToast(notification);
            });
        } catch (requestError) {
            mostrarError(requestError.message);
        } finally {
            cargando = false;
        }
    };

    toggle.addEventListener("click", () => {
        const abierto = toggle.getAttribute("aria-expanded") === "true";
        toggle.setAttribute("aria-expanded", String(!abierto));
        popover.hidden = abierto;
        if (!abierto) cargar();
    });

    document.addEventListener("click", async (event) => {
        const link = event.target.closest("[data-notification-id]");
        if (!link || link.classList.contains("is-read")) return;
        event.preventDefault();
        try {
            await solicitar(endpoint, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
                body: formData({ action: "leer", id: link.dataset.notificationId }),
            });
            window.location.assign(link.href);
        } catch (requestError) {
            mostrarError(requestError.message);
        }
    });

    markAll.addEventListener("click", async () => {
        try {
            await solicitar(endpoint, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
                body: formData({ action: "leer_todas" }),
            });
            await cargar();
        } catch (requestError) {
            mostrarError(requestError.message);
        }
    });

    document.addEventListener("click", (event) => {
        if (!center.contains(event.target) && toggle.getAttribute("aria-expanded") === "true") {
            toggle.setAttribute("aria-expanded", "false");
            popover.hidden = true;
        }
    });

    cargar();
    window.setInterval(cargar, 15000);
})();
