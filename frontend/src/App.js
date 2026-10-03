import { useEffect } from "react";
import "@/App.css";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import axios from "axios";
import { HOME } from "@/constants/testIds";

const BACKEND_URL = process.env.REACT_APP_BACKEND_URL;
const API = `${BACKEND_URL}/api`;
const apiClient = axios.create({
  baseURL: API,
  timeout: 5000,
});

const Home = () => {
  useEffect(() => {
    const controller = new AbortController();

    apiClient
      .get("/", { signal: controller.signal })
      .then((response) => {
        if (process.env.NODE_ENV !== "production") {
          console.debug(response.data.message);
        }
      })
      .catch((error) => {
        if (error.code !== "ERR_CANCELED") {
          console.error("Request to /api failed", error);
        }
      });

    return () => controller.abort();
  }, []);

  return (
    <div data-emergent-splash>
      <header className="App-header">
        <a
          data-testid={HOME.emergentLink}
          className="App-link"
          href="https://emergent.sh"
          target="_blank"
          rel="noopener noreferrer"
        >
          <img
            src="https://avatars.githubusercontent.com/in/1201222?s=120&u=2686cf91179bbafbc7a71bfbc43004cf9ae1acea&v=4"
            alt="Emergent"
            width="120"
            height="120"
            loading="eager"
          />
        </a>
        <p className="mt-5">Building something incredible ~!</p>
      </header>
    </div>
  );
};

function App() {
  return (
    <div className="App">
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<Home />} />
        </Routes>
      </BrowserRouter>
    </div>
  );
}

export default App;
