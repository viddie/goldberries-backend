function getCookie(name, dontDecode = false) {
  function escape(s) {
    return s.replace(/([.*+?\^$(){}|\[\]\/\\])/g, "\\$1");
  }
  var match = document.cookie.match(RegExp("(?:^|;\\s*)" + escape(name) + "=([^;]*)"));
  var value = match ? match[1] : null;

  if (value && !dontDecode) {
    return decodeBase64(value);
  }
  return value;
}

function escapeHtml(unsafe) {
  if (unsafe === null || unsafe === undefined) return null;
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function sel(selector) {
  return document.querySelector(selector);
}

const DIFFICULTIES = {
  25: {
    color: "#d863ff",
    name: "Tier 21+",
    sort: 21,
    old_name: "",
    old_name_label_color: "#ffffff",
    shares: 4,
  },
  24: {
    color: "#f266ff",
    name: "Tier 20",
    sort: 20,
    old_name: "",
    old_name_label_color: "#ffffff",
    shares: 4,
  },
  //Tier 0
  2: {
    color: "#ff68d9",
    name: "Tier 19",
    sort: 19,
    old_name: "Mid Tier 0",
    old_name_label_color: "#ffffff",
    shares: 4,
  },
  3: {
    color: "#ff6daa",
    name: "Tier 18",
    sort: 18,
    old_name: "Low Tier 0",
    old_name_label_color: "#ffffff",
    shares: 4,
  },

  //Tier 0.5
  23: {
    color: "#ff6d79",
    name: "Tier 17",
    sort: 17,
    old_name: "Tier 0.5",
    old_name_label_color: "#ffffff",
    shares: 4,
  },

  //Tier 1
  4: {
    color: "#ff7c70",
    name: "Tier 16",
    sort: 16,
    old_name: "High Tier 1",
    old_name_label_color: "#ffffff",
    shares: 4,
  },
  5: {
    color: "#ff9572",
    name: "Tier 15",
    sort: 15,
    old_name: "Mid Tier 1",
    old_name_label_color: "#ffffff",
    shares: 4,
  },
  6: {
    color: "#ffae75",
    name: "Tier 14",
    sort: 14,
    old_name: "Low Tier 1",
    old_name_label_color: "#ffffff",
    shares: 4,
  },

  //Tier 2
  7: {
    color: "#ffc677",
    name: "Tier 13",
    sort: 13,
    old_name: "High Tier 2",
    old_name_label_color: "#777777",
    shares: 4,
  },
  8: {
    color: "#ffdd7a",
    name: "Tier 12",
    sort: 12,
    old_name: "Mid Tier 2",
    old_name_label_color: "#777777",
    shares: 4,
  },
  9: {
    color: "#fff47c",
    name: "Tier 11",
    sort: 11,
    old_name: "Low Tier 2",
    old_name_label_color: "#777777",
    shares: 4,
  },

  //Tier 3
  10: {
    color: "#f4ff7f",
    name: "Tier 10",
    sort: 10,
    old_name: "High Tier 3",
    old_name_label_color: "#777777",
    shares: 4,
  },
  11: {
    color: "#d5ff82",
    name: "Tier 9",
    sort: 9,
    old_name: "Mid Tier 3",
    old_name_label_color: "#777777",
    shares: 4,
  },
  12: {
    color: "#b7ff84",
    name: "Tier 8",
    sort: 8,
    old_name: "Low Tier 3",
    old_name_label_color: "#777777",
    shares: 4,
  },

  //Tier 4
  14: {
    color: "#9bff87",
    name: "Tier 7",
    sort: 7,
    old_name: "Tier 4",
    old_name_label_color: "#777777",
    shares: 4,
  },

  //Tier 5
  15: {
    color: "#89ffb0",
    name: "Tier 6",
    sort: 6,
    old_name: "Tier 5",
    old_name_label_color: "#777777",
    shares: 4,
  },

  //Tier 6
  16: {
    color: "#8cffe2",
    name: "Tier 5",
    sort: 5,
    old_name: "Tier 6",
    old_name_label_color: "#777777",
    shares: 4,
  },

  //Tier 7
  17: {
    color: "#8eecff",
    name: "Tier 4",
    sort: 4,
    old_name: "Tier 7",
    old_name_label_color: "#777777",
    shares: 4,
  },

  //High Standard
  22: {
    color: "#91c8ff",
    name: "Tier 3",
    sort: 3,
    old_name: "High Standard",
    old_name_label_color: "#777777",
    shares: 4,
  },

  //Mid Standard
  18: {
    color: "#93aeff",
    name: "Tier 2",
    sort: 2,
    old_name: "Mid Standard",
    old_name_label_color: "#ffffff",
    shares: 4,
  },

  //Low Standard
  21: {
    color: "#9696ff",
    name: "Tier 1",
    sort: 1,
    old_name: "Low Standard",
    old_name_label_color: "#ffffff",
    shares: 6,
  },

  //Trivial
  20: {
    color: "#ffffff",
    name: "Untiered",
    sort: 0,
    old_name: "Trivial",
    old_name_label_color: "#6f6f6f",
    shares: 12,
  },

  //Undetermined
  19: {
    color: "#aaaaaa",
    name: "Undetermined",
    sort: -1,
    old_name: "Undetermined",
    old_name_label_color: "#6f6f6f",
    shares: 6,
  },
};

function decodeBase64(base64) {
  return decodeURIComponent(
    atob(base64)
      .split("")
      .map(function (c) {
        return "%" + ("00" + c.charCodeAt(0).toString(16)).slice(-2);
      })
      .join("")
  );
}
