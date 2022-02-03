import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom';
import create from 'zustand'

let uid = 0;
const container = document.createElement('div');
container.className = "notifications-container";

const useStore = create(set => ({
    notifications: [],
    log: (msg, options = {}, details = undefined) => set(state => {
        if (!container.parentNode) {
            document.body.prepend(container);
        }
        const notifications = [...state.notifications];
        notifications.push({msg, options, details, id: uid++});
        if (options.timeout !== undefined && options.timeout > 0) {
            setTimeout(
                () => {
                    state.remove();
                },
                options.timeout
            )
        }
        return { notifications };
    }),
    remove: () => set({ notifications: [] })
}))

ReactDOM.render(
    <Notifier />,
    container
);

function Notifier() {
    const {notifications, remove} = useStore();

    return <div className={"notifications " + (notifications.length > 0 ? "visible" : "")}>
        {notifications.map((notification) => {
            return <div key={notification.id} className="notification" onClick={notification.options.timeout ? remove : () => {}}>
                {notification.options.timeout ? null : <a onClick={remove} className="close">x</a>}
                <div className="title">{notification.msg}</div>
                {notification.details && <div className="content" dangerouslySetInnerHTML={{__html: notification.details}}></div>}
            </div>;
        })}
    </div>;
}

const {log, remove} = useStore.getState()

export default {log, remove};
