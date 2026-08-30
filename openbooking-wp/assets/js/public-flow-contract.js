function resolveBookingCreateFlow(res) {
    var bookingToken = null;
    var shouldStartPayment = false;

    if (res.success && res.booking) {
        bookingToken = (res.payment && res.payment.token) || null;
        shouldStartPayment = !!(res.payment && res.payment.required);
    }

    return {
        bookingToken: bookingToken,
        shouldStartPayment: shouldStartPayment,
    };
}

function resolveConfirmAttendanceFlow(previewResponse) {
    if (!previewResponse || !previewResponse.success) {
        return { canRender: false, reason: 'error' };
    }

    if (previewResponse.already_confirmed) {
        return { canRender: true, state: 'already_confirmed' };
    }

    if (!previewResponse.can_confirm_attendance) {
        return { canRender: true, state: 'not_confirmable' };
    }

    return { canRender: true, state: 'needs_confirmation' };
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        resolveBookingCreateFlow: resolveBookingCreateFlow,
        resolveConfirmAttendanceFlow: resolveConfirmAttendanceFlow,
    };
}
